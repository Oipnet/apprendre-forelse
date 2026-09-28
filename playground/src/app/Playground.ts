import type { CompletionIndex } from '../editor/completion';
import { PreviewBridge } from '../preview/bridge';
import type { CommandResult } from '@forelse/runtime-contract';
import '../runtime/builtin';
import { createRuntime, runtimeLabel } from '../runtime/registry';
import { ConsolePanel } from './console';
import { cheminsExplicites, estModifiable } from './editable';
import { Emitter, Mailbox } from './emitter';
import { FeedbackDialog } from './feedback';
import { HintsAndSolution } from './HintsAndSolution';
import { escapeHtml } from './html';
import { layout } from './layout';
import { MentorClient, errorTextOf, stripAnsi } from './mentor';
import { MentorPanel } from './MentorPanel';
import { mesurer } from '../mesure';
import { bindPaneTabs, bindWorkpanes, bootProgress, fitEditor } from './panes';
import { ApiProgressStore, LocalProgressStore, type ProgressStore } from './progress';
import { ProjectFilesController } from './ProjectFilesController';
import { RequestsPanel } from './requests';
import { ExerciseSession } from './session';
import { SuccessPanel } from './SuccessPanel';
import { spokenSummary, TestResultsView } from './TestResultsView';
import type { ExercisePayload, PlaygroundConfig } from './types';

/** Monte l'environnement d'exercice complet dans `root`. */
export async function mountPlayground(root: HTMLElement, config: PlaygroundConfig) {
	const metrics: Record<string, number> = {};
	const t0 = performance.now();
	const mark = (name: string) => (metrics[name] = Math.round(performance.now() - t0));
	// Monaco pèse plusieurs Mo : importé statiquement, il se téléchargeait avant même que le worker ne démarre.
	// Il arrive maintenant pendant que PHP démarre, et n'est attendu qu'au moment de créer l'éditeur.
	const editeur = Promise.all([import('../editor/monaco'), import('../editor/completion'), import('./diff')]);
	editeur.catch(() => {}); // attendu plus bas : son échec éventuel s'y affiche

	const response = await fetch(config.exerciseUrl, { headers: { accept: 'application/json' }, credentials: 'same-origin' });
	if (!response.ok) {
		root.textContent = `Impossible de charger l'exercice (${response.status}).`;
		return;
	}
	const exercise = (await response.json()) as ExercisePayload;
	// Ce que le moteur sait du framework : le playground le lit, il ne le redéclare pas (voir FrameworkProfile).
	const framework = exercise.environment.framework;
	/** La console du projet, telle qu'un développeur la tape dans son terminal. */
	const consoleName = framework.console;
	// Un invité joue la Pratique (sans parcours : clé `formation:null/<id>`, reprise telle quelle par entries/site.ts).
	const progress: ProgressStore =
		config.progress.mode === 'api' ? new ApiProgressStore(config.progress.url) : new LocalProgressStore(`formation:${exercise.trackId}/${exercise.id}`, exercise.xp);
	const practice = config.context === 'practice';
	/** Ce qui identifie l'exercice dans la mesure d'audience : du contenu public, rien de l'apprenant. */
	const mesure = { exercice: exercise.id, parcours: exercise.trackId ?? 'pratique' };

	root.innerHTML = layout(exercise, config);
	const $ = <T extends HTMLElement>(selector: string) => root.querySelector<T>(selector)!;
	/** La barre de statut est annoncée (role="status") : `spoken` remplace, pour un lecteur d'écran, un texte trop abrégé. */
	const status = (text: string, kind: 'idle' | 'busy' | 'ok' | 'ko' = 'idle', spoken?: string) => {
		const el = $('.status');
		if (spoken) {
			const visible = document.createElement('span');
			visible.setAttribute('aria-hidden', 'true');
			visible.textContent = text;
			const said = document.createElement('span');
			said.className = 'sr-only';
			said.textContent = spoken;
			el.replaceChildren(visible, said);
		} else {
			el.textContent = text;
		}
		el.dataset.kind = kind;
	};

	// --- Petits écrans : un volet à la fois, explorateur replié, éditeur compact -------------
	const narrow = window.matchMedia('(max-width: 800px)');
	const compact = window.matchMedia('(max-width: 1100px)');
	const revealPane = bindWorkpanes(root, narrow);

	// --- Le mentor (comptes seulement, et si le serveur a une clé d'API) -------------------
	const mentor = new MentorPanel(root, config.mentor ? new MentorClient(config.mentor) : null, () => revealPane('brief'));

	// --- Runtime et aperçu isolé ---------------------------------------------------------
	const runtime = createRuntime(framework.runtime);
	const urlInput = $<HTMLInputElement>('#url');
	// La console est créée plus bas : les messages de l'aperçu arrivés avant sont gardés en attente.
	const messagesDeLApercu = new Mailbox<[level: 'warn' | 'error', message: string]>();
	const versLaConsole = (level: 'warn' | 'error', message: string) => messagesDeLApercu.post([level, message]);
	const bridge = new PreviewBridge(runtime, $<HTMLIFrameElement>('#frame'), config.sandboxUrl, {
		onConsole: versLaConsole,
		onNavigated: (path) => {
			urlInput.value = path || '/';
			$('#preview-frozen').hidden = true;
		},
		// Une boucle infinie dans le code de la page (onMounted, watch, clic…) gèle l'aperçu : le relais est
		// recréé, mais la page n'est pas rechargée, elle retomberait dans la boucle. La prochaine modification,
		// ou ⟳, la recharge.
		onFrozen: () => {
			$('#preview-frozen').hidden = false;
			status('La page de l\'aperçu ne répond plus : boucle infinie dans son code ?', 'ko');
			versLaConsole('error', 'La page de l\'aperçu ne répond plus depuis plusieurs secondes : boucle infinie dans son code (onMounted, watch, gestionnaire d\'événement…), ou boîte de dialogue (alert, confirm) restée ouverte ? L\'aperçu a été relancé sans recharger cette page. Corrigez la boucle, puis rechargez l\'aperçu (⟳).');
		},
		onRecovered: () => status('Aperçu relancé. Corrigez la boucle, puis rechargez-le (⟳).', 'idle'),
		onRestartFailed: (message) => {
			status('L\'aperçu n\'a pas pu être relancé : rechargez-le (⟳) pour réessayer.', 'ko');
			versLaConsole('error', `Relais de l'aperçu : ${message}`);
		},
		onResponse: (request, res) => {
			const path = request.url.slice(bridge.base.length) || '/';
			$('#requestlog').innerHTML = `<span class="method">${escapeHtml(request.method)}</span> ${escapeHtml(path)} <span class="code c${String(res.status)[0]}">${res.status}</span> · ${Math.round(res.durationMs)} ms`;
			if (!metrics.firstRequest) mark('firstRequest');
			// Une réponse en erreur : le mentor peut l'expliquer (si l'apprenant a un compte).
			mentor.setError('preview', res.status >= 500 ? `${request.method} ${path} → ${res.status}\n\n${errorTextOf(res.body, res.headers['content-type']?.[0])}` : null);
		},
	});
	// Le relais de l'aperçu (page du bac à sable, Service Worker) se prépare pendant que PHP démarre : il n'a
	// besoin du runtime qu'à la première requête. Il attendait la fin du boot et l'écriture des fichiers.
	const relais = bridge.start();
	relais.catch(() => {}); // attendu plus bas : son échec éventuel s'y affiche
	try {
		await runtime.boot(
			{
				id: exercise.environment.id,
				framework,
				options: { phpVersion: exercise.environment.phpVersion },
				// URL absolue : le worker peut tourner depuis une URL blob: (dev), sans base relative.
				archiveUrl: new URL(exercise.environment.archiveUrl, location.href).href,
				previewBasePath: bridge.base,
				previewSecure: new URL(config.sandboxUrl).protocol === 'https:',
			},
			bootProgress(root),
		);
	} catch (error) {
		$('#boot-label').textContent = `Impossible de démarrer ${runtimeLabel(framework.runtime)} : ${error instanceof Error ? error.message.split('\n')[0] : error}`;
		throw error;
	}
	mark('runtimeReady');

	const saved = await progress.load().catch(() => null);
	const initial: Record<string, string> = { ...exercise.files };
	// Les motifs (migrations/*.php) couvrent les fichiers générés lors d'une session précédente.
	for (const [path, content] of Object.entries(saved?.files ?? {})) if (estModifiable(path, exercise.editable)) initial[path] = content;
	const editables = [...new Set([...cheminsExplicites(exercise.editable), ...Object.keys(initial).filter((path) => estModifiable(path, exercise.editable))])];
	// Un seul message pour tout le projet de départ, au lieu d'un aller-retour par fichier.
	await runtime.writeFiles({ ...initial, ...exercise.tests });
	mark('filesWritten');

	await relais;
	mark('relayReady');
	const reload = () => bridge.navigate(urlInput.value || '/');
	urlInput.addEventListener('keydown', (e) => e.key === 'Enter' && reload());
	$('#reload').addEventListener('click', reload);

	// --- Aperçu / console ----------------------------------------------------------------
	bindPaneTabs(root, Boolean(runtime.runCommand));
	// Une commande peut créer ou modifier des fichiers (doctrine:migrations:diff…) : l'éditeur s'y abonne plus bas.
	const commandRan = new Emitter<CommandResult>();
	const consolePanel = new ConsolePanel($('#console-output'), $<HTMLInputElement>('#console-cmd'), runtime, (result) => {
		commandRan.emit(result);
		mentor.setError('console', result.exitCode === 0 ? null : stripAnsi(result.output));
		reload();
	}, consoleName, framework.consoleAliases, framework.rawCommands);
	messagesDeLApercu.deliverTo(([level, message]) => {
		consolePanel.logFromPreview(level, message);
		// Le badge signale un message qu'on n'a pas encore vu, sauf si la console est déjà affichée.
		if ($('.pane-console').hidden) {
			$('.pane-tabs .badge').title = 'La page de l\'aperçu a signalé un problème';
			$('.pane-tabs .badge').hidden = false;
		}
	});
	// Un runtime sans console (le simulateur Nuxt) : l'onglet garde les messages de l'aperçu, sans champ de commande.
	const commandes = exercise.setup.length > 0 && runtime.runCommand ? exercise.setup : [];
	if (!runtime.runCommand) $('.console-input').hidden = true;

	// Commandes de préparation (ex. schéma de la base de l'aperçu, qui vit en mémoire).
	for (const command of commandes) {
		$('#boot-label').textContent = `Préparation : ${consoleName} ${command}`;
		const result = await consolePanel.run(command, { quiet: true });
		if (result?.exitCode !== 0) $('.pane-tabs .badge').hidden = false;
	}
	bridge.navigate(exercise.preview);

	// Une boucle infinie bloque le runtime : il est relancé avec les fichiers, mais ce qui vivait en mémoire
	// (la base de l'aperçu) est à refaire. L'aperçu n'est pas rechargé : il retomberait dans la boucle.
	// La prochaine modification le rechargera.
	runtime.onRestart?.((event) => {
		if (event.phase === 'restarting') status('Le code ne répond plus (boucle infinie ?) : redémarrage…', 'ko');
		else if (event.phase === 'failed') status(`Impossible de redémarrer : ${event.error}. Rechargez la page.`, 'ko');
		else void (async () => {
			for (const command of commandes) await consolePanel.run(command, { quiet: true });
			status('Redémarré. Corrigez la boucle, puis relancez.', 'idle');
		})();
	});

	// --- Éditeur -------------------------------------------------------------------------
	const session = new ExerciseSession(runtime, progress, {
		files: Object.fromEntries(editables.map((p) => [p, initial[p] ?? ''])),
		hintsUsed: saved?.hintsUsed ?? 0,
		solutionRevealed: saved?.solutionRevealed ?? false,
		completed: saved?.completed ?? false,
	}, { onWritten: reload });
	const [{ EditorPanel, monaco }, { registerCompletion }, { BeforeAfterDialog }] = await editeur;
	mark('editorLoaded');
	const editor = new EditorPanel($('#tabs'), $('#editor'), (path, content) => session.edit(path, content));
	const files = new ProjectFilesController(root, editor, runtime, exercise, session, compact.matches);
	await files.addInitialFiles(initial, editables);
	// Explorateur : tout le projet, sauf les tests cachés de l'exercice (ils donneraient la solution).
	files.load();
	commandRan.on((result) => files.synchronise(result));
	fitEditor(editor, narrow);
	files.openStart();

	// Onglet « Requêtes » : les dernières frappes sont écrites dans PHP avant chaque envoi.
	new RequestsPanel($('.pane-requests'), exercise.requests, runtime, bridge.base, new URL(config.sandboxUrl).host, async () => (await session.flush()) && reload());

	fetch(exercise.environment.completionIndexUrl)
		.then((r) => r.json() as Promise<CompletionIndex>)
		// Les fichiers du projet, édités compris : la complétion y lit les classes de l'apprenant.
		.then((index) => registerCompletion(monaco.languages, index, () => monaco.editor.getModels(), framework.snippets, () => ({ ...initial, ...session.current })))
		.catch((e) => console.warn('Complétion indisponible', e));

	// --- Mentor, tests et réussite -------------------------------------------------------
	mentor.connect({
		files: () => session.current,
		openAt: async (path, line) => {
			await files.openFile(path);
			if (line > 0) editor.goToLine(line);
		},
	});
	const success = new SuccessPanel(root, exercise, config, {
		session,
		mentor,
		/** Pratique : le code de départ face au code de l'apprenant. */
		beforeAfter: practice ? new BeforeAfterDialog(root, exercise.files, () => session.current) : null,
		savedReview: saved?.review,
		mesure,
	});
	const results = new TestResultsView(root, mentor);
	const runButton = $<HTMLButtonElement>('#run');
	runButton.addEventListener('click', async () => {
		runButton.disabled = true;
		mesurer('tests-lances', mesure);
		status('Tests en cours…', 'busy');
		try {
			// Les dernières frappes doivent être testées, pas perdues.
			if (await session.flush()) reload();
			const result = await runtime.runTests({ ownTests: exercise.ownTests, mutants: exercise.mutants });
			metrics[metrics.firstTestRun ? 'lastTestRun' : 'firstTestRun'] = Math.round(result.durationMs);
			const passed = results.show(result);
			const total = exercise.objectives.length;
			status(`${passed}/${total} objectifs · tests en ${Math.round(result.durationMs)} ms`, passed === total ? 'ok' : 'ko', spokenSummary(passed, total));
			revealPane('brief');
			if (passed === total) void success.show();
			else success.hide();
		} catch (error) {
			status('Erreur pendant les tests', 'ko');
			results.showError(error);
		} finally {
			runButton.disabled = false;
		}
	});

	// --- Indices et solution -------------------------------------------------------------
	new HintsAndSolution(root, exercise, config, { session, editor, revealPane, mesure });

	// --- Retour sur l'exercice (apprenant connecté) ----------------------------------------
	if (config.feedbackUrl) new FeedbackDialog(root, $<HTMLButtonElement>('#feedback'), config.feedbackUrl, () => ({ hintsUsed: session.hintsUsed, completed: session.completed }));

	// Rappel « déjà réussi » : la revue de code, la fiche de cours et le bouton avant/après.
	if (session.completed) success.markCompleted(true);

	// --- Prêt ----------------------------------------------------------------------------
	runButton.disabled = false;
	const overlay = $('.overlay');
	overlay.classList.add('hidden');
	// Estompé, il resterait lu au clavier et par les lecteurs d'écran : il sort de l'arbre d'accessibilité.
	overlay.inert = true;
	overlay.setAttribute('aria-hidden', 'true');
	mark('ready');
	mesurer('exercice-ouvert', { ...mesure, compte: config.progress.mode === 'api' });
	status(`Prêt en ${(metrics.ready / 1000).toFixed(1)} s`, 'ok');
	Object.assign(window, { playground: { metrics, runtime, editor: editor.instance, monaco } }); // debug / mesures
}
