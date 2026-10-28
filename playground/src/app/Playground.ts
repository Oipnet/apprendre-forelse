import type { CompletionIndex } from '../editor/completion';
import { PreviewBridge } from '../preview/bridge';
import type { CommandResult } from '@forelse/runtime-contract';
import '../runtime/builtin';
import { createRuntime, runtimeLabel } from '../runtime/registry';
import { apresDemarrage } from './attente';
import { ConsolePanel } from './console';
import { DatabasePanel } from './database';
import { cheminsExplicites, estModifiable } from './editable';
import { Emitter, Mailbox } from './emitter';
import { undoToast } from './dialogs';
import { FeedbackDialog } from './feedback';
import { PaneFolding, type Foldable } from './folding';
import { shareDialog } from './share';
import { HintsAndSolution } from './HintsAndSolution';
import { escapeHtml } from './html';
import { layout } from './layout';
import { bindRetry, bootFailureActionsHtml, bootKey, firstLoadNote, loadErrorHtml, suivreDemarrage } from './boot';
import { MentorClient, errorTextOf, stripAnsi } from './mentor';
import { MentorPanel } from './MentorPanel';
import { m } from '../i18n';
import { mesurer } from '../mesure';
import { bindPaneTabs, bindWorkpanes, bootProgress, fitEditor } from './panes';
import { ApiProgressStore, LocalProgressStore, type ProgressStore, type SavedProgress } from './progress';
import { ProjectFilesController } from './ProjectFilesController';
import { RequestsPanel } from './requests';
import { changedFiles, decodeFragment, encodeFragment, NEW_PROJECT, withFragment } from './scratch';
import { ExerciseSession } from './session';
import { modifierLabel, shortcutOf, shortcutsHelpHtml, type Shortcut } from './shortcuts';
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

	const response = await fetch(config.exerciseUrl, { headers: { accept: 'application/json' }, credentials: 'same-origin' }).catch(() => null);
	if (!response?.ok) {
		root.innerHTML = loadErrorHtml(response?.status ?? null, config.loginUrl);
		bindRetry(root);
		return;
	}
	const exercise = (await response.json()) as ExercisePayload;
	// Ce que le moteur sait du framework : le playground le lit, il ne le redéclare pas (voir FrameworkProfile).
	const framework = exercise.environment.framework;
	/** La console du projet, telle qu'un développeur la tape dans son terminal. */
	const consoleName = framework.console;
	const practice = config.context === 'practice';
	/** Le bac à sable : ni objectif ni test, un brouillon dans le navigateur, et le code partagé par l'adresse (scratch.ts). */
	const scratch = config.context === 'scratch';
	// Un invité joue la Pratique (sans parcours : clé `formation:null/<id>`, reprise telle quelle par entries/site.ts).
	// Le brouillon du bac à sable a sa propre clé : il n'a rien à remonter sur un compte.
	const progress: ProgressStore =
		config.progress.mode === 'api'
			? new ApiProgressStore(config.progress.url)
			: new LocalProgressStore(scratch ? `forelse:bac-a-sable:${exercise.id}` : `formation:${exercise.trackId}/${exercise.id}`, exercise.xp);
	/** Le code arrivé par un lien partagé : il remplace le brouillon, et l'aperçu attend qu'on accepte de l'exécuter. */
	const shared = scratch ? decodeFragment(location.hash) : null;
	/** « #nouveau » (les cartes de /bac-a-sable) : un projet vierge, le brouillon reste repris en un clic. */
	const fresh = scratch && !shared && location.hash === `#${NEW_PROJECT}`;
	/** Ce qui identifie l'exercice dans la mesure d'audience : du contenu public, rien de l'apprenant. */
	const mesure = { exercice: exercise.id, parcours: scratch ? 'bac-a-sable' : (exercise.trackId ?? 'pratique') };

	root.innerHTML = layout(exercise, config);
	const $ = <T extends HTMLElement>(selector: string) => root.querySelector<T>(selector)!;

	// L'aide « Raccourcis clavier » : lisible dès l'affichage, même si le démarrage échoue ensuite.
	const mod = modifierLabel(navigator.platform);
	const help = document.createElement('dialog');
	help.className = 'feedback shortcuts';
	help.setAttribute('aria-labelledby', 'shortcuts-title');
	help.innerHTML = shortcutsHelpHtml(mod, navigator.platform);
	root.append(help);
	help.querySelector('[data-close]')!.addEventListener('click', () => help.close());
	$('#shortcuts').addEventListener('click', () => help.showModal());
	// Partager l'exercice : comme l'aide, utilisable même si le démarrage échoue. La boîte propose aussi le partage du
	// système quand le navigateur en a un.
	if (config.share && !scratch) {
		const share = config.share.invite;
		$<HTMLButtonElement>('#share').addEventListener('click', () => shareDialog(root, share, m.layout.shareTitle, mesure));
	}
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
	const showWorkpane = bindWorkpanes(root, narrow);
	// --- Écrans plus larges : consignes et aperçu repliables, état retenu pour tous les exercices ---
	const folding = new PaneFolding(root, narrow, compact, (() => {
		try {
			return localStorage;
		} catch {
			return null; // stockage bloqué : tout ouvert, rien de retenu
		}
	})(), (volet: Foldable, replie: boolean) => mesurer('volet-replie', { ...mesure, volet, replie }));
	/** Amène l'apprenant sur un volet : celui du petit écran, et les consignes rouvertes si elles étaient repliées. */
	const revealPane = (pane: 'brief' | 'code') => {
		showWorkpane(pane);
		if (pane === 'brief') folding.open('brief');
	};

	// --- Le mentor (comptes seulement, et si le serveur a une clé d'API) -------------------
	const mentor = new MentorPanel(root, config.mentor ? new MentorClient(config.mentor) : null, () => revealPane('brief'));

	// --- Runtime et aperçu isolé ---------------------------------------------------------
	// La page se monte sans attendre PHP : consignes, éditeur et explorateur s'affichent tout de suite, et chaque appel
	// au runtime (frappes, lecture d'un fichier, aperçu) attend `demarre` : PHP prêt, projet de départ écrit. Seuls le
	// bouton Exécuter et la mise en route de l'aperçu attendent la fin de tout le démarrage.
	const moteur = createRuntime(framework.runtime);
	let demarrer!: (etapes: Promise<void>) => void;
	const demarre = new Promise<void>((resolve) => (demarrer = resolve));
	demarre.catch(() => {}); // l'échec s'affiche plus bas, dans l'écran de démarrage
	const runtime = apresDemarrage(moteur, demarre);
	const urlInput = $<HTMLInputElement>('#url');
	// La console est créée plus bas : les messages de l'aperçu arrivés avant sont gardés en attente.
	const messagesDeLApercu = new Mailbox<[level: 'warn' | 'error', message: string]>();
	const versLaConsole = (level: 'warn' | 'error', message: string) => messagesDeLApercu.post([level, message]);
	// L'onglet « Base », créé plus bas : chaque réponse de l'aperçu peut avoir changé la base.
	let base: DatabasePanel | null = null;
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
			status(m.playground.previewFrozen, 'ko');
			versLaConsole('error', m.playground.previewFrozenConsole);
		},
		onRecovered: () => status(m.playground.previewRecovered, 'idle'),
		onRestartFailed: (message) => {
			status(m.playground.previewRestartFailed, 'ko');
			versLaConsole('error', m.preview.relayError(message));
		},
		onResponse: (request, res) => {
			const path = request.url.slice(bridge.base.length) || '/';
			$('#requestlog').innerHTML = `<span class="method">${escapeHtml(request.method)}</span> ${escapeHtml(path)} <span class="code c${String(res.status)[0]}">${res.status}</span> · ${Math.round(res.durationMs)} ms`;
			if (!metrics.firstRequest) mark('firstRequest');
			base?.changed();
			// Une réponse en erreur : le mentor peut l'expliquer (si l'apprenant a un compte).
			mentor.setError('preview', res.status >= 500 ? `${request.method} ${path} → ${res.status}\n\n${errorTextOf(res.body, res.headers['content-type']?.[0])}` : null);
		},
	});
	// Le relais de l'aperçu (page du bac à sable, Service Worker) se prépare pendant que PHP démarre : il n'a
	// besoin du runtime qu'à la première requête. Il attendait la fin du boot et l'écriture des fichiers.
	const relais = bridge.start();
	relais.catch(() => {}); // attendu plus bas : son échec éventuel s'y affiche
	// Premier téléchargement de cette archive : on prévient que c'est long, et que ce ne sera plus le cas.
	const archiveUrl = new URL(exercise.environment.archiveUrl, location.href).href;
	const seen = (() => {
		try {
			return localStorage.getItem(bootKey(exercise.environment.id));
		} catch {
			return null; // stockage indisponible : on prévient par défaut
		}
	})();
	const note = firstLoadNote(seen, archiveUrl);
	if (note) $('#boot-first').textContent = note;
	const demarrage = suivreDemarrage(mesure, note !== null, t0);
	// Le brouillon d'une session précédente se charge pendant le téléchargement : il fait partie du projet de départ.
	const depart = (shared ? Promise.resolve<SavedProgress | null>({ files: shared, hintsUsed: 0, completed: false }) : progress.load())
		.catch(() => null)
		.then((loaded) => {
			const saved = fresh ? null : loaded;
			const initial: Record<string, string> = { ...exercise.files };
			// Les motifs (migrations/*.php) couvrent les fichiers générés lors d'une session précédente.
			for (const [path, content] of Object.entries(saved?.files ?? {})) if (estModifiable(path, exercise.editable)) initial[path] = content;
			return { saved, initial, draft: fresh ? (loaded?.files ?? null) : null };
		});
	const suivreProgression = bootProgress(root);
	demarrer(
		(async () => {
			await moteur.boot(
				{
					id: exercise.environment.id,
					framework,
					options: {
						phpVersion: exercise.environment.phpVersion,
						// Les options d'un runtime sont des chaînes : la liste voyage séparée par des virgules.
						...(exercise.environment.phpExtensions?.length ? { phpExtensions: exercise.environment.phpExtensions.join(',') } : {}),
					},
					// URL absolue : le worker peut tourner depuis une URL blob: (dev), sans base relative.
					archiveUrl,
					previewBasePath: bridge.base,
					previewSecure: new URL(config.sandboxUrl).protocol === 'https:',
				},
				(p) => {
					suivreProgression(p);
					// L'écran de démarrage ne couvre que l'aperçu (replié, ou caché sur petit écran) : la barre de statut le dit aussi.
					status(p.label, 'busy');
				},
			);
			mark('runtimeReady');
			try {
				localStorage.setItem(bootKey(exercise.environment.id), archiveUrl);
			} catch {
				// stockage indisponible : le prochain démarrage préviendra encore, sans conséquence
			}
			// Un seul message pour tout le projet de départ, au lieu d'un aller-retour par fichier.
			await moteur.writeFiles({ ...(await depart).initial, ...exercise.tests });
			mark('filesWritten');
		})(),
	);

	const { saved, initial, draft } = await depart;
	const editables = [...new Set([...cheminsExplicites(exercise.editable), ...Object.keys(initial).filter((path) => estModifiable(path, exercise.editable))])];

	/** Recharge l'aperçu ; pendant le démarrage, rien : il s'ouvrira de lui-même à la fin. */
	let apercuPret = false;
	const reload = () => {
		if (apercuPret) bridge.navigate(urlInput.value || '/');
	};
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
		// Aperçu replié : sa rail le signale aussi.
		if ($('.pane-console').hidden) {
			$('.pane-tabs .badge').title = m.playground.previewProblem;
			$('.pane-tabs .badge').hidden = false;
		}
		folding.showPreviewAlert(m.playground.previewProblemSpoken);
	});
	// Un runtime sans console (le simulateur Nuxt) : l'onglet garde les messages de l'aperçu, sans champ de commande.
	const commandes = exercise.setup.length > 0 && runtime.runCommand ? exercise.setup : [];
	if (!runtime.runCommand) $('.console-input').hidden = true;

	// Une boucle infinie bloque le runtime : il est relancé avec les fichiers, mais ce qui vivait en mémoire
	// (la base de l'aperçu) est à refaire. L'aperçu n'est pas rechargé : il retomberait dans la boucle.
	// La prochaine modification le rechargera.
	moteur.onRestart?.((event) => {
		if (event.phase === 'restarting') status(m.playground.restarting, 'ko');
		else if (event.phase === 'failed') status(m.playground.restartFailed(String(event.error)), 'ko');
		else void (async () => {
			for (const command of commandes) await consolePanel.run(command, { quiet: true });
			status(m.playground.restarted, 'idle');
		})();
	});

	// --- Éditeur -------------------------------------------------------------------------
	const session = new ExerciseSession(runtime, progress, {
		files: Object.fromEntries(editables.map((p) => [p, initial[p] ?? ''])),
		hintsUsed: saved?.hintsUsed ?? 0,
		solutionRevealed: saved?.solutionRevealed ?? false,
		completed: saved?.completed ?? false,
	}, {
		onWritten: reload,
		// L'enregistrement automatique échoue (session expirée, accès terminé) : l'apprenant doit le savoir avant de fermer.
		onDraftError: (error) => status(m.playground.draftNotSaved(error.message), 'ko'),
	});
	window.addEventListener('pagehide', () => session.saveOnExit());
	if (fresh) history.replaceState(history.state, '', location.pathname + location.search);
	if (shared) {
		// Le code partagé devient le brouillon, et l'adresse le perd : recharger la page ne l'imposera plus aux
		// modifications faites depuis.
		void session.saveDraft();
		history.replaceState(history.state, '', location.pathname + location.search);
	}
	// Partager le bac à sable : le lien porte les fichiers modifiés, les dernières frappes comprises.
	if (config.share && scratch) {
		const share = config.share.invite;
		$<HTMLButtonElement>('#share').addEventListener('click', async () => {
			await session.flush().catch(() => false);
			shareDialog(root, withFragment(share, encodeFragment(changedFiles(session.current, exercise.files))), m.scratch.shareTitle, mesure);
		});
	}
	const [{ EditorPanel, monaco }, { registerCompletion }, { BeforeAfterDialog }] = await editeur;
	mark('editorLoaded');
	const editor = new EditorPanel($('#tabs'), $('#editor'), (path, content) => session.edit(path, content));
	const files = new ProjectFilesController(root, editor, runtime, exercise, session, compact.matches, scratch ? m.scratch.newProject : m.files);
	// Les fichiers modifiables arrivent tout de suite ; ceux à lire seulement, quand PHP peut les lire.
	const fichiersALire = files.addInitialFiles(initial, editables);
	// Explorateur : tout le projet, sauf les tests cachés de l'exercice (ils donneraient la solution).
	files.load();
	commandRan.on((result) => files.synchronise(result));
	fitEditor(editor, narrow);
	// Projet vierge demandé alors qu'un brouillon existait : il ne sera remplacé qu'à la première frappe.
	if (draft && Object.keys(changedFiles(draft, exercise.files)).length) undoToast(root, m.scratch.newProjectDone, m.scratch.resumeDraft, () => files.restore(draft));
	if (editor.has(exercise.open)) files.openStart();
	else void fichiersALire.then(() => files.openStart(), () => {});
	mark('editorShown');

	// Onglet « Requêtes » : les dernières frappes sont écrites dans PHP avant chaque envoi.
	new RequestsPanel($('.pane-requests'), exercise.requests, runtime, bridge.base, new URL(config.sandboxUrl).host, async () => (await session.flush()) && reload());

	// Onglet « Base » : seulement si le runtime sait lire les bases du projet, et à partir de la première créée.
	if (runtime.inspectDatabase) {
		base = new DatabasePanel($('.pane-database'), $('.pane-tabs button[data-pane="database"]'), () => runtime.inspectDatabase!());
		commandRan.on(() => base?.changed());
		base.changed();
	}

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
	// Le bac à sable n'a pas de tests : « Exécuter » envoie les dernières frappes et recharge l'aperçu.
	if (scratch) runButton.addEventListener('click', async () => {
		await session.flush().catch(() => false);
		reload();
		mesurer('bac-a-sable-execute', mesure);
		status(m.scratch.ran, 'ok');
	});
	else runButton.addEventListener('click', async () => {
		runButton.disabled = true;
		runButton.setAttribute('aria-busy', 'true');
		mesurer('tests-lances', mesure);
		status(m.playground.testsRunning, 'busy');
		try {
			// Les dernières frappes doivent être testées, pas perdues.
			if (await session.flush()) reload();
			const result = await runtime.runTests({ ownTests: exercise.ownTests, mutants: exercise.mutants });
			metrics[metrics.firstTestRun ? 'lastTestRun' : 'firstTestRun'] = Math.round(result.durationMs);
			const passed = results.show(result);
			const total = exercise.objectives.length;
			status(m.playground.testsDone(passed, total, Math.round(result.durationMs)), passed === total ? 'ok' : 'ko', spokenSummary(passed, total));
			// Petit écran : les consignes, où s'affichent les objectifs. Volet replié : il ne se rouvre qu'à la
			// réussite, pour ne pas reprendre la place pendant que l'apprenant corrige ; sa rail porte le résultat.
			showWorkpane('brief');
			folding.showResult(passed, total);
			if (passed === total) {
				folding.open('brief');
				void success.show();
			} else success.hide();
		} catch (error) {
			status(m.playground.testsError, 'ko');
			results.showError(error);
		} finally {
			runButton.disabled = false;
			runButton.removeAttribute('aria-busy');
		}
	});

	// --- Indices et solution -------------------------------------------------------------
	new HintsAndSolution(root, exercise, config, { session, editor, revealPane, mesure });

	// --- Raccourcis clavier : Monaco les reçoit quand il a le focus, le document ailleurs --------
	runButton.title = scratch ? m.scratch.runTitle(mod) : m.playground.runTitle(mod);
	const saveNow = async () => {
		try {
			if (await session.flush()) reload();
			await session.saveNow();
			status(m.playground.draftSaved, 'ok');
		} catch (error) {
			status(m.playground.draftNotSaved(error instanceof Error ? error.message : String(error)), 'ko');
		}
	};
	const act = (shortcut: Shortcut) => {
		if (shortcut === 'save') void saveNow();
		else if (shortcut === 'fold-brief') {
			if (!scratch) folding.toggle('brief');
		}
		else if (shortcut === 'fold-preview') folding.toggle('preview');
		else if (!runButton.disabled) runButton.click();
	};
	editor.instance.addCommand(monaco.KeyMod.CtrlCmd | monaco.KeyCode.Enter, () => act('run'));
	editor.instance.addCommand(monaco.KeyMod.CtrlCmd | monaco.KeyCode.KeyS, () => act('save'));
	// WinCtrl : la touche Ctrl sur toutes les plateformes, Mac compris (voir shortcutOf).
	editor.instance.addCommand(monaco.KeyMod.WinCtrl | monaco.KeyMod.Alt | monaco.KeyCode.KeyB, () => act('fold-brief'));
	editor.instance.addCommand(monaco.KeyMod.WinCtrl | monaco.KeyMod.Alt | monaco.KeyCode.KeyP, () => act('fold-preview'));
	document.addEventListener('keydown', (event) => {
		const shortcut = event.defaultPrevented ? null : shortcutOf(event);
		if (!shortcut) return;
		event.preventDefault(); // Ctrl+S : pas la boîte « Enregistrer la page » du navigateur
		act(shortcut);
	});

	// --- Retour sur l'exercice (apprenant connecté) ----------------------------------------
	if (config.feedbackUrl) new FeedbackDialog(root, $<HTMLButtonElement>('#feedback'), config.feedbackUrl, () => ({ hintsUsed: session.hintsUsed, completed: session.completed }));

	// Rappel « déjà réussi » : la revue de code, la fiche de cours et le bouton avant/après.
	if (session.completed) success.markCompleted(true);

	// --- Fin du démarrage : PHP, puis les commandes de préparation et l'aperçu -------------------
	try {
		await demarre;
	} catch (error) {
		$('#boot-label').textContent = m.boot.runtimeFailed(runtimeLabel(framework.runtime), error instanceof Error ? error.message.split('\n')[0] : String(error));
		$('#boot-first').textContent = '';
		status(m.boot.startFailed, 'ko');
		$('.boot').insertAdjacentHTML('beforeend', bootFailureActionsHtml());
		bindRetry(root, () => {
			const overlay = $('.overlay');
			overlay.classList.add('hidden');
			overlay.inert = true;
			revealPane('brief');
		});
		root.dataset.bootFailed = '';
		demarrage.echec(error);
		throw error;
	}
	await relais;
	mark('relayReady');
	// Commandes de préparation (ex. schéma de la base de l'aperçu, qui vit en mémoire).
	for (const command of commandes) {
		$('#boot-label').textContent = m.boot.preparing(`${consoleName} ${command}`);
		status(m.boot.preparing(`${consoleName} ${command}`), 'busy');
		const result = await consolePanel.run(command, { quiet: true });
		if (result?.exitCode !== 0) {
			$('.pane-tabs .badge').hidden = false;
			folding.showPreviewAlert(m.playground.setupFailedSpoken);
		}
	}
	// Un lien partagé porte le code de quelqu'un d'autre : il se lit dans l'éditeur, mais ne s'exécute qu'avec l'accord
	// du visiteur. L'aperçu (autre origine, sans cookie) en limite la portée ; une fausse page ou une boucle restent possibles.
	if (shared) {
		status(m.scratch.sharedStatus, 'idle');
		const boot = $('.boot');
		boot.innerHTML = `<p role="status"><strong>${m.scratch.sharedTitle}</strong></p><p>${m.scratch.sharedDetail}</p><p class="boot-actions"><button type="button" class="primary" data-run-shared>▶ ${m.scratch.sharedRun}</button></p>`;
		await new Promise<void>((resolve) => boot.querySelector('[data-run-shared]')!.addEventListener('click', () => resolve(), { once: true }));
		mesurer('bac-a-sable-code-partage-execute', mesure);
	}
	apercuPret = true;
	// Les frappes faites pendant le chargement sont dans PHP avant que l'aperçu ne s'ouvre.
	await session.flush().catch(() => false);
	bridge.navigate(exercise.preview);

	// --- Prêt ----------------------------------------------------------------------------
	runButton.disabled = false;
	const overlay = $('.overlay');
	overlay.classList.add('hidden');
	// Estompé, il resterait lu au clavier et par les lecteurs d'écran : il sort de l'arbre d'accessibilité.
	overlay.inert = true;
	overlay.setAttribute('aria-hidden', 'true');
	mark('ready');
	demarrage.pret({ compte: config.progress.mode === 'api', ...(scratch ? { partage: shared !== null } : {}) });
	status(m.playground.ready(metrics.ready / 1000), 'ok');
	Object.assign(window, { playground: { metrics, runtime, editor: editor.instance, monaco } }); // debug / mesures
}
