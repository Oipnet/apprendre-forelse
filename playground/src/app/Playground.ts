import DOMPurify from 'dompurify';
import { marked } from 'marked';
import type { CompletionIndex } from '../editor/completion';
import { PreviewBridge } from '../preview/bridge';
import type { CommandResult, TestRunResult } from '@forelse/runtime-contract';
import '../runtime/builtin';
import { createRuntime, runtimeLabel } from '../runtime/registry';
import { ConsolePanel } from './console';
import { FeedbackDialog } from './feedback';
import { cheminsExplicites, contenuDeDepart, estModifiable } from './editable';
import { FileTree } from './filetree';
import { MentorClient, errorTextOf, renderExplanation, renderReview, stripAnsi, type ErrorSource } from './mentor';
import { RequestsPanel } from './requests';
import { mesurer } from '../mesure';
import { ApiProgressStore, LocalProgressStore, xpFor, type ProgressStore } from './progress';
import type { ExercisePayload, PlaygroundConfig } from './types';

const escapeHtml = (s: string) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!);
/** Le contenu vient d'un pack : on le nettoie avant de l'injecter dans la plateforme. */
const markdown = (source: string, inline = false) => DOMPurify.sanitize(inline ? (marked.parseInline(source) as string) : (marked.parse(source) as string));

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
	// Un invité ne joue que des exercices de parcours (la Pratique demande un compte) : la clé locale a toujours son parcours.
	const progress: ProgressStore =
		config.progress.mode === 'api' ? new ApiProgressStore(config.progress.url) : new LocalProgressStore(`formation:${exercise.trackId}/${exercise.id}`, exercise.xp);
	const practice = config.context === 'practice';
	/** Ce qui identifie l'exercice dans la mesure d'audience : du contenu public, rien de l'apprenant. */
	const mesure = { exercice: exercise.id, parcours: exercise.trackId ?? 'pratique' };

	root.innerHTML = layout(exercise, config);
	const $ = <T extends HTMLElement>(selector: string) => root.querySelector<T>(selector)!;
	const status = (text: string, kind: 'idle' | 'busy' | 'ok' | 'ko' = 'idle') => {
		const el = $('.status');
		el.textContent = text;
		el.dataset.kind = kind;
	};

	// --- Le mentor (comptes seulement, et si le serveur a une clé d'API) -------------------
	const mentor = config.mentor ? new MentorClient(config.mentor) : null;
	let lastPreviewError: string | null = null;

	// --- Petits écrans : un volet à la fois, explorateur replié, éditeur compact -------------
	type Workpane = 'brief' | 'code' | 'preview';
	const narrow = window.matchMedia('(max-width: 800px)');
	const compact = window.matchMedia('(max-width: 1100px)');
	const workspace = $('.workspace');
	const showWorkpane = (pane: Workpane) => {
		workspace.dataset.pane = pane;
		for (const b of root.querySelectorAll<HTMLButtonElement>('.pane-switch button')) b.classList.toggle('active', b.dataset.pane === pane);
	};
	for (const b of root.querySelectorAll<HTMLButtonElement>('.pane-switch button')) b.addEventListener('click', () => showWorkpane(b.dataset.pane as Workpane));
	/** Sur petit écran seulement : amène l'apprenant sur le volet où quelque chose vient de se passer. */
	const revealPane = (pane: Workpane) => narrow.matches && showWorkpane(pane);
	$('.small-screen-note button').addEventListener('click', () => ($('.small-screen-note').style.display = 'none'));

	// --- Runtime et aperçu isolé ---------------------------------------------------------
	const runtime = createRuntime(framework.runtime);
	const urlInput = $<HTMLInputElement>('#url');
	// La console est créée plus bas : les messages de l'aperçu arrivés avant sont gardés en attente.
	let messagesDeLApercu: ((level: 'warn' | 'error', message: string) => void) | undefined;
	const enAttente: [level: 'warn' | 'error', message: string][] = [];
	const versLaConsole = (level: 'warn' | 'error', message: string) => (messagesDeLApercu ? messagesDeLApercu(level, message) : enAttente.push([level, message]));
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
			lastPreviewError = res.status >= 500 ? `${request.method} ${path} → ${res.status}\n\n${errorTextOf(res.body, res.headers['content-type']?.[0])}` : null;
			$('#explain-preview').hidden = !mentor || !lastPreviewError;
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
				phpVersion: exercise.environment.phpVersion,
				// URL absolue : le worker peut tourner depuis une URL blob: (dev), sans base relative.
				archiveUrl: new URL(exercise.environment.archiveUrl, location.href).href,
				previewBasePath: bridge.base,
				previewSecure: new URL(config.sandboxUrl).protocol === 'https:',
			},
			(p) => {
				$('#boot-label').textContent = p.label;
				$('#boot-bar').style.width = p.ratio === null ? '100%' : `${Math.round(p.ratio * 100)}%`;
				$('#boot-bar').classList.toggle('indeterminate', p.ratio === null);
			},
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
	type Pane = 'preview' | 'console' | 'requests';
	const showPane = (pane: Pane) => {
		for (const tab of root.querySelectorAll<HTMLButtonElement>('.pane-tabs button')) tab.classList.toggle('active', tab.dataset.pane === pane);
		for (const name of ['preview', 'console', 'requests'] as const) $(`.pane-${name}`).hidden = pane !== name;
		if (pane === 'console') {
			$('.pane-tabs .badge').hidden = true;
			$<HTMLInputElement>('#console-cmd').focus();
		}
	};
	for (const tab of root.querySelectorAll<HTMLButtonElement>('.pane-tabs button')) tab.addEventListener('click', () => showPane(tab.dataset.pane as Pane));
	let lastConsoleError: string | null = null;
	// Une commande peut créer ou modifier des fichiers (doctrine:migrations:diff…) : branché plus bas, sur l'éditeur.
	let synchroniserLesFichiers: (result: CommandResult) => void = () => {};
	const consolePanel = new ConsolePanel($('#console-output'), $<HTMLInputElement>('#console-cmd'), runtime, (result) => {
		synchroniserLesFichiers(result);
		lastConsoleError = result.exitCode === 0 ? null : stripAnsi(result.output);
		$('#explain-console').hidden = !mentor || !lastConsoleError;
		reload();
	}, consoleName, framework.consoleAliases);
	messagesDeLApercu = (level, message) => {
		consolePanel.logFromPreview(level, message);
		// Le badge signale un message qu'on n'a pas encore vu, sauf si la console est déjà affichée.
		if ($('.pane-console').hidden) {
			$('.pane-tabs .badge').title = 'La page de l\'aperçu a signalé un problème';
			$('.pane-tabs .badge').hidden = false;
		}
	};
	for (const [level, message] of enAttente.splice(0)) messagesDeLApercu(level, message);

	// Commandes de préparation (ex. schéma de la base de l'aperçu, qui vit en mémoire).
	for (const command of exercise.setup) {
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
			for (const command of exercise.setup) await consolePanel.run(command, { quiet: true });
			status('Redémarré. Corrigez la boucle, puis relancez.', 'idle');
		})();
	});

	// --- Éditeur -------------------------------------------------------------------------
	// Écritures vers PHP en attente, par fichier : un debounce global perdrait la
	// modification d'un fichier quand un autre change juste après.
	const pending = new Map<string, string>();
	const current: Record<string, string> = Object.fromEntries(editables.map((p) => [p, initial[p] ?? '']));
	let writeTimer: number | undefined;
	let saveTimer: number | undefined;
	let hintsUsed = saved?.hintsUsed ?? 0;
	let solutionRevealed = saved?.solutionRevealed ?? false;
	const saveDraft = () => {
		clearTimeout(saveTimer);
		saveTimer = window.setTimeout(() => progress.saveDraft(current, hintsUsed).catch((e) => console.warn(e)), 1500);
	};
	async function flushPending(): Promise<boolean> {
		clearTimeout(writeTimer);
		const changes = [...pending];
		pending.clear();
		for (const [path, content] of changes) await runtime.writeFile(path, content);
		return changes.length > 0;
	}
	const [{ EditorPanel, monaco }, { registerCompletion }, { BeforeAfterDialog }] = await editeur;
	mark('editorLoaded');
	const editor = new EditorPanel($('#tabs'), $('#editor'), (path, content) => {
		current[path] = content;
		pending.set(path, content);
		clearTimeout(writeTimer);
		writeTimer = window.setTimeout(() => {
			flushPending()
				.then((changed) => changed && reload())
				// Runtime redémarré en cours d'écriture : l'écriture est rejouée par le runtime neuf, rien n'est perdu.
				.catch((error) => console.warn('Écriture différée vers le runtime', error));
		}, 400);
		saveDraft();
	});
	// Un motif (src/Entity/*.php) couvre souvent des fichiers déjà là : ils s'ouvrent depuis l'explorateur, pas tous d'emblée.
	const ouvertsAuDepart = new Set([...cheminsExplicites(exercise.editable), exercise.open, ...Object.keys(initial).filter((path) => !(path in exercise.files))]);
	for (const path of editables) if (ouvertsAuDepart.has(path)) editor.addFile(path, initial[path] ?? '');
	for (const path of exercise.readonly) editor.addFile(path, initial[path] ?? (await runtime.readFile(path)) ?? '', true);

	// Explorateur : tout le projet, sauf les tests cachés de l'exercice (ils donneraient la solution).
	const caches = new Set(Object.keys(exercise.tests));
	let tree: FileTree | undefined;
	const openFile = async (path: string) => {
		if (caches.has(path)) return;
		if (!editor.has(path)) {
			const content = await runtime.readFile(path);
			if (null === content) return;
			editor.addFile(path, content, !estModifiable(path, exercise.editable));
		}
		editor.open(path);
		tree?.setActive(path);
	};
	const cheminsDuProjet = new Set<string>();
	const dessinerLArbre = () => {
		tree = new FileTree($('#filetree-body'), [...cheminsDuProjet].filter((path) => !caches.has(path)), exercise.editable, (path) => void openFile(path));
		const actif = editor.currentPath();
		if (actif) tree.setActive(actif);
	};
	runtime
		.listFiles()
		.then((paths) => {
			for (const path of paths) cheminsDuProjet.add(path);
			dessinerLArbre();
		})
		.catch((e) => console.warn('Explorateur indisponible', e));

	synchroniserLesFichiers = ({ fichiers = {}, supprimes = [] }) => {
		let nouveaux = false;
		let aOuvrir: string | undefined;
		for (const [path, content] of Object.entries(fichiers)) {
			if (caches.has(path)) continue;
			if (!cheminsDuProjet.has(path)) {
				cheminsDuProjet.add(path);
				nouveaux = true;
			}
			if (editor.has(path)) {
				editor.setContent(path, content);
			} else if (estModifiable(path, exercise.editable)) {
				// Un fichier généré que l'apprenant doit relire ou compléter : on l'ouvre tout de suite.
				editor.addFile(path, content);
				aOuvrir = path;
			}
			if (estModifiable(path, exercise.editable)) current[path] = content;
		}
		for (const path of supprimes) {
			if (editor.has(path)) editor.removeFile(path);
			delete current[path];
			nouveaux = cheminsDuProjet.delete(path) || nouveaux;
		}
		if (aOuvrir) editor.open(aOuvrir);
		if (nouveaux) dessinerLArbre();
		if (Object.keys(fichiers).length || supprimes.length) saveDraft();
	};
	// Créer un fichier, là où l'exercice le permet (un motif comme src/Entity/*.php).
	const motifsModifiables = exercise.editable.filter((entree) => entree.includes('*'));
	const boutonNouveau = $<HTMLButtonElement>('#new-file');
	boutonNouveau.hidden = motifsModifiables.length === 0;
	boutonNouveau.addEventListener('click', async () => {
		const autorises = motifsModifiables.join(', ');
		const chemin = prompt(`Chemin du nouveau fichier (autorisés : ${autorises})`, motifsModifiables[0].replace('*', 'Nouveau'))?.trim().replace(/^\/+/, '');
		if (!chemin) return;
		if (chemin.split('/').includes('..') || !estModifiable(chemin, exercise.editable)) {
			alert(`« ${chemin} » ne fait pas partie des fichiers modifiables de cet exercice (${autorises}).`);
			return;
		}
		if (cheminsDuProjet.has(chemin) || editor.has(chemin)) {
			await openFile(chemin);
			return;
		}
		const contenu = contenuDeDepart(chemin, framework);
		await runtime.writeFile(chemin, contenu);
		synchroniserLesFichiers({ exitCode: 0, output: '', durationMs: 0, fichiers: { [chemin]: contenu } });
		tree?.setActive(chemin);
	});
	$('#toggle-tree').addEventListener('click', () => {
		const explorateur = $('#filetree');
		explorateur.hidden = !explorateur.hidden;
		$('#toggle-tree').classList.toggle('open', !explorateur.hidden);
	});
	if (compact.matches) {
		// Fenêtre étroite : l'explorateur prendrait la place du code. Un clic le rouvre.
		$('#filetree').hidden = true;
		$('#toggle-tree').classList.remove('open');
	}
	// Éditeur compact sur petit écran : police plus petite, retour à la ligne, gouttière réduite.
	const fitEditor = () =>
		editor.instance.updateOptions(
			narrow.matches ? { fontSize: 13, wordWrap: 'on', lineNumbersMinChars: 3, folding: false, glyphMargin: false } : { fontSize: 14, wordWrap: 'off', lineNumbersMinChars: 5, folding: true, glyphMargin: true },
		);
	fitEditor();
	narrow.addEventListener('change', fitEditor);

	editor.open(exercise.open);
	tree?.setActive(exercise.open);

	// Onglet « Requêtes » : les dernières frappes sont écrites dans PHP avant chaque envoi.
	new RequestsPanel($('.pane-requests'), exercise.requests, runtime, bridge.base, new URL(config.sandboxUrl).host, async () => (await flushPending()) && reload());

	fetch(exercise.environment.completionIndexUrl)
		.then((r) => r.json() as Promise<CompletionIndex>)
		// Les fichiers du projet, édités compris : la complétion y lit les classes de l'apprenant.
		.then((index) => registerCompletion(monaco.languages, index, () => monaco.editor.getModels(), framework.snippets, () => ({ ...initial, ...current })))
		.catch((e) => console.warn('Complétion indisponible', e));

	// --- Tests et réussite ---------------------------------------------------------------
	const runButton = $<HTMLButtonElement>('#run');
	let completed = saved?.completed ?? false;
	/** « ✓ Réussi » dans la barre ; au retour sur un exercice déjà réussi, un rappel dans les consignes. */
	const markCompleted = (onReturn = false) => {
		$('#done-chip').hidden = false;
		if (!onReturn) return;
		// Les objectifs restent cochés : ils ont été validés lors de la réussite.
		for (const li of root.querySelectorAll<HTMLLIElement>('.objectives li')) li.dataset.state = 'passed';
		const back = $('#already-done');
		back.hidden = false;
		back.innerHTML = practice
			? '✓ Vous avez déjà réussi cet exercice.'
			: exercise.next
			? `✓ Vous avez déjà réussi cet exercice. <a href="${escapeHtml(exercise.next.url)}">Exercice suivant : ${escapeHtml(exercise.next.title)} →</a>`
			: exercise.nextTrack
				? `✓ Vous avez déjà réussi cet exercice, le dernier du parcours. <a href="${escapeHtml(exercise.nextTrack.url)}">Continuer avec « ${escapeHtml(exercise.nextTrack.title)} » →</a>`
				: '✓ Vous avez déjà réussi cet exercice.';
		back.innerHTML += lessonLink(' ');
		if (practice) back.append(' ', beforeAfterButton());
		if (saved?.review) showReview(saved.review);
	};
	/** Pratique : le code de départ face au code de l'apprenant. */
	const beforeAfter = practice ? new BeforeAfterDialog(root, exercise.files, () => current) : null;
	const beforeAfterButton = () => {
		const button = document.createElement('button');
		button.className = 'ghost small';
		button.textContent = '↔ Avant / après';
		button.addEventListener('click', () => beforeAfter?.open());
		return button;
	};
	/** Lien vers la fiche de cours débloquée par cet exercice (comptes seulement : l'invité n'y a pas accès). */
	const lessonLink = (before = '') =>
		exercise.lesson && config.progress.mode === 'api'
			? `${before}<a class="lesson-unlocked" href="${escapeHtml(exercise.lesson.url)}">📜 Fiche de cours du chapitre « ${escapeHtml(exercise.lesson.title)} » →</a>`
			: '';

	async function onSuccess() {
		const panel = $('#success');
		panel.hidden = false;
		try {
			// Le code qui a réussi est enregistré avant la réussite (le brouillon attend sinon son délai).
			clearTimeout(saveTimer);
			await progress.saveDraft(current, hintsUsed);
			const result = await progress.complete(hintsUsed);
			completed = true;
			if (!result.alreadyCompleted) mesurer('exercice-reussi', { ...mesure, indices: hintsUsed, solution: solutionRevealed });
			markCompleted();
			const gain = result.alreadyCompleted ? 'Exercice déjà validé.' : practice ? '' : solutionRevealed && result.xpEarned === 0 ? 'Sans XP : la solution a été consultée.' : `+${result.xpEarned} XP`;
			const total = result.totalXp !== null && !practice ? ` · ${result.totalXp} XP au total` : '';
			if (result.totalXp !== null) root.querySelector('#user-xp')?.replaceChildren(`⭐ ${result.totalXp} XP`);
			let next = '';
			if (practice) {
				const source = exercise.practice?.pullRequest
					? `<p>Envie de voir comment la fonctionnalité est faite ? <a href="${escapeHtml(exercise.practice.pullRequest)}" target="_blank" rel="noopener noreferrer">La pull request d'origine</a>.</p>`
					: '';
				next = `${source}<a class="button" href="${escapeHtml(config.back.url)}">Retour à la Pratique</a>`;
			} else if (exercise.next && config.progress.mode === 'local' && config.registerUrl) {
				next = config.waitlistUrl
					? `<p>La suite du parcours est en bêta fermée. Vous avez un code d'invitation ? Créez votre compte pour continuer. Sinon, laissez votre adresse : vous serez prévenu à l'ouverture.</p><a class="button primary" href="${escapeHtml(config.registerUrl)}">J'ai un code d'invitation</a> <a class="button ghost small" href="${escapeHtml(config.waitlistUrl)}">Rejoindre la liste d'attente</a>`
					: `<p>Créez un compte gratuit pour sauvegarder votre progression et continuer.</p><a class="button primary" href="${escapeHtml(config.registerUrl)}">Créer mon compte</a>`;
			} else if (exercise.next) {
				next = `<a class="button primary" href="${escapeHtml(exercise.next.url)}">Exercice suivant : ${escapeHtml(exercise.next.title)} →</a>`;
			} else if (exercise.nextTrack) {
				const track = exercise.nextTrack;
				next = `<p>C'était le dernier exercice de « ${escapeHtml(config.back.title)} ». Et maintenant ? Continuez avec <strong>${escapeHtml(track.title)}</strong>${track.description ? ` : ${escapeHtml(track.description)}` : '.'}</p>
					<a class="button primary" href="${escapeHtml(track.url)}">Commencer « ${escapeHtml(track.title)} » →</a>
					<a class="button ghost small" href="${escapeHtml(config.back.url)}">Retour au parcours</a>`;
			} else {
				next = `<p>C'était le dernier exercice de « ${escapeHtml(config.back.title)} ».</p><a class="button" href="${escapeHtml(config.back.url)}">Retour au parcours</a>`;
			}
			panel.innerHTML = `<strong>🎉 Exercice réussi ! ${gain}${total}</strong>${lessonLink('<p>Vous avez terminé le chapitre.</p>')}${next}`;
			if (practice) panel.querySelector('.button')?.before(beforeAfterButton(), ' ');
			if (mentor && !saved?.review) {
				// Une revue de code, sur demande : un regard sur ce qui pourrait être plus idiomatique.
				const ask = document.createElement('button');
				ask.className = 'ghost small';
				ask.textContent = '🔍 Demander une revue de code';
				ask.addEventListener('click', async () => {
					ask.disabled = true;
					ask.textContent = 'Le mentor relit votre code…';
					try {
						showReview(await mentor.review(current));
						ask.remove();
					} catch (error) {
						ask.disabled = false;
						ask.textContent = '🔍 Demander une revue de code';
						$('#review').hidden = false;
						$('#review').innerHTML = `<strong>🔍 Revue de code</strong><p class="muted">${escapeHtml(error instanceof Error ? error.message : String(error))}</p>`;
					}
				});
				panel.append(ask);
			}
		} catch (error) {
			panel.innerHTML = `<strong>🎉 Exercice réussi !</strong><p>La progression n'a pas pu être enregistrée : ${escapeHtml(String(error))}</p>`;
		}
	}

	/** Les liens « fichier:ligne » du mentor ouvrent le fichier dans l'éditeur. */
	const bindFileLinks = (host: HTMLElement) => {
		for (const link of host.querySelectorAll<HTMLAnchorElement>('a[data-open]')) {
			link.addEventListener('click', async (event) => {
				event.preventDefault();
				await openFile(link.dataset.open!);
				const line = Number(link.dataset.line);
				if (line > 0) editor.goToLine(line);
			});
		}
	};
	const showReview = (review: Parameters<typeof renderReview>[0]) => {
		const box = $('#review');
		box.hidden = false;
		box.innerHTML = renderReview(review, (source) => markdown(source, true));
		bindFileLinks(box);
	};
	/** Demande au mentor d'expliquer une erreur, et affiche sa réponse sous les objectifs. */
	const explain = async (button: HTMLButtonElement, error: string, source: ErrorSource) => {
		if (!mentor) return;
		const box = $('#explain');
		const label = button.textContent;
		button.disabled = true;
		button.textContent = 'Le mentor regarde…';
		box.hidden = false;
		box.innerHTML = '<strong>🩺 Le mentor</strong><p class="muted">Il lit l\'erreur et votre code…</p>';
		try {
			box.innerHTML = renderExplanation(await mentor.explain(current, error, source), (s) => markdown(s, true));
			bindFileLinks(box);
		} catch (e) {
			box.innerHTML = `<strong>🩺 Le mentor</strong><p class="muted">${escapeHtml(e instanceof Error ? e.message : String(e))}</p>`;
		} finally {
			button.disabled = false;
			button.textContent = label;
		}
		revealPane('brief');
		box.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
	};
	$('#explain-preview').addEventListener('click', (e) => lastPreviewError && explain(e.currentTarget as HTMLButtonElement, lastPreviewError, 'preview'));
	$('#explain-console').addEventListener('click', (e) => lastConsoleError && explain(e.currentTarget as HTMLButtonElement, lastConsoleError, 'console'));
	let lastTestError: string | null = null;
	$('#explain-tests').addEventListener('click', (e) => {
		e.preventDefault(); // dans un <summary> : ne pas replier la sortie
		if (lastTestError) void explain(e.currentTarget as HTMLButtonElement, lastTestError, 'tests');
	});

	function showResults(result: TestRunResult) {
		const byName = new Map(result.cases.map((c) => [c.name, c]));
		let passed = 0;
		for (const li of root.querySelectorAll<HTMLLIElement>('.objectives li')) {
			const test = byName.get(li.dataset.test!);
			const ok = test?.status === 'passed';
			passed += ok ? 1 : 0;
			li.dataset.state = !test ? 'unknown' : ok ? 'passed' : 'failed';
			li.querySelector('.why')?.remove();
			if (test && !ok && test.message) {
				const why = document.createElement('div');
				why.className = 'why';
				// Message pédagogique du test, sans l'en-tête « Classe::méthode » ni la trace.
				const lines = test.message.split('\n').filter((l) => l.trim() && !l.startsWith('/') && !/^App\\Tests\\\S+$/.test(l));
				// Un message écrit par l'auteur de l'exercice suffit ; sinon on garde le détail PHPUnit.
				const authored = lines.length > 1 && lines[1].startsWith('Failed asserting') && !lines[0].includes('\\');
				why.textContent = lines.slice(0, authored ? 1 : 2).map((l) => (l.length > 160 ? `${l.slice(0, 160)}…` : l)).join('\n');
				li.append(why);
			}
		}
		const total = exercise.objectives.length;
		$('#output').textContent = result.output;
		$('#output-box').hidden = false;
		if (!result.cases.length) $<HTMLDetailsElement>('#output-box').open = true;
		// Une erreur (exception, fatal) se distingue d'un échec d'assertion, que les indices couvrent.
		const errors = result.cases.filter((c) => c.status === 'error');
		lastTestError = !result.cases.length ? result.output.slice(-6000) : errors.length ? errors.map((c) => `${c.name}\n${c.message ?? ''}`).join('\n\n') : null;
		$('#explain-tests').hidden = !mentor || !lastTestError;
		status(`${passed}/${total} objectifs · tests en ${Math.round(result.durationMs)} ms`, passed === total ? 'ok' : 'ko');
		revealPane('brief');
		if (passed === total) void onSuccess();
		else $('#success').hidden = true;
	}

	runButton.addEventListener('click', async () => {
		runButton.disabled = true;
		mesurer('tests-lances', mesure);
		status('Tests en cours…', 'busy');
		try {
			// Les dernières frappes doivent être testées, pas perdues.
			if (await flushPending()) reload();
			const result = await runtime.runTests({ ownTests: exercise.ownTests, mutants: exercise.mutants });
			metrics[metrics.firstTestRun ? 'lastTestRun' : 'firstTestRun'] = Math.round(result.durationMs);
			showResults(result);
		} catch (error) {
			status('Erreur pendant les tests', 'ko');
			$('#output').textContent = String(error);
			$('#output-box').hidden = false;
			lastTestError = String(error);
			$('#explain-tests').hidden = !mentor;
		} finally {
			runButton.disabled = false;
		}
	});

	// --- Indices, réinitialisation, solution ---------------------------------------------
	const hintButton = $<HTMLButtonElement>('#hint');
	const showHint = (index: number) => {
		const hint = document.createElement('div');
		hint.className = 'hint';
		hint.innerHTML = `<strong>Indice ${index + 1}/${exercise.hints.length}</strong> ${markdown(exercise.hints[index], true)}`;
		$('#hints').append(hint);
	};
	const updateHintButton = () => {
		const cost = xpFor(exercise.xp, hintsUsed) - xpFor(exercise.xp, hintsUsed + 1);
		hintButton.hidden = hintsUsed >= exercise.hints.length;
		hintButton.textContent = completed || solutionRevealed || cost <= 0 ? '💡 Un indice ?' : `💡 Un indice ? (−${cost} XP)`;
	};
	for (let i = 0; i < Math.min(hintsUsed, exercise.hints.length); i++) showHint(i);
	updateHintButton();
	hintButton.addEventListener('click', () => {
		if (hintsUsed >= exercise.hints.length) return;
		showHint(hintsUsed);
		revealPane('brief');
		hintsUsed++;
		mesurer('indice-demande', { ...mesure, indices: hintsUsed });
		updateHintButton();
		saveDraft();
	});
	$('#reset').addEventListener('click', () => {
		if (!confirm('Revenir au code de départ ? Vos modifications seront perdues.')) return;
		for (const path of Object.keys(current)) {
			if (path in exercise.files && !editor.has(path)) {
				// Couvert par un motif, jamais ouvert : on remet simplement le fichier de départ.
				current[path] = exercise.files[path];
				void runtime.writeFile(path, exercise.files[path]);
			} else if (path in exercise.files) {
				editor.setContent(path, exercise.files[path]);
			} else if (!cheminsExplicites(exercise.editable).includes(path)) {
				// Créé depuis le départ (par la console) : il n'existait pas, il disparaît.
				editor.removeFile(path);
				delete current[path];
				cheminsDuProjet.delete(path);
				void runtime.deleteFile(path);
			} else {
				editor.setContent(path, '');
			}
		}
		dessinerLArbre();
		saveDraft();
	});
	// La solution de référence, à côté du code de l'apprenant (onglets en lecture seule), contre l'XP.
	const solutionButton = $<HTMLButtonElement>('#solution');
	const solutionNote = $('#solution-note');
	const showSolution = (files: Record<string, string>) => {
		let first: string | undefined;
		for (const [path, content] of Object.entries(files)) {
			const tabPath = `solution/${path}`;
			if (!editor.has(tabPath)) editor.addFile(tabPath, content, true, `✓ ${path.split('/').pop()!}`);
			first ??= tabPath;
		}
		if (first) editor.open(first);
		revealPane('code');
	};
	const noteSolution = () => {
		solutionNote.hidden = false;
		solutionNote.textContent = completed || exercise.xp === 0 ? 'Solution consultée.' : 'Solution consultée : cet exercice ne rapportera pas d\'XP.';
		solutionButton.querySelector('.label')!.textContent = 'Solution ✓';
		updateHintButton();
	};
	if (config.progress.mode === 'api') {
		solutionButton.hidden = false;
		solutionButton.title = exercise.xp === 0 ? 'La solution de référence.' : 'La solution de référence. La consulter ne rapporte pas l\'XP de cet exercice.';
		if (solutionRevealed) noteSolution();
		solutionButton.addEventListener('click', async () => {
			if (!completed && !solutionRevealed && exercise.xp > 0 && !confirm('Consulter la solution ? Cet exercice ne rapportera alors pas d\'XP, même réussi ensuite avec votre code.')) return;
			solutionButton.disabled = true;
			try {
				const files = await progress.revealSolution();
				if (!files) return;
				solutionRevealed = true;
				mesurer('solution-consultee', mesure);
				noteSolution();
				showSolution(files);
			} catch (error) {
				alert(`Solution indisponible : ${error instanceof Error ? error.message : error}`);
			} finally {
				solutionButton.disabled = false;
			}
		});
	} else if (exercise.solution) {
		// Développement de la plateforme : la solution remplace le code, pour tester vite.
		solutionButton.hidden = false;
		solutionButton.title = 'Visible en développement uniquement';
		solutionButton.addEventListener('click', () => {
			for (const [path, content] of Object.entries(exercise.solution!)) editor.setContent(path, content);
		});
	}

	// --- Retour sur l'exercice (apprenant connecté) ----------------------------------------
	if (config.feedbackUrl) new FeedbackDialog(root, $<HTMLButtonElement>('#feedback'), config.feedbackUrl, () => ({ hintsUsed, completed }));

	// Rappel « déjà réussi » : posé ici, une fois tout déclaré. Il affiche la revue de code, la fiche de
	// cours et le bouton avant/après, qui vivent plus bas dans ce fichier — appelé plus haut, il tombait
	// sur des fonctions encore dans leur zone morte (ReferenceError, et l'exercice ne s'ouvrait plus).
	if (completed) markCompleted(true);

	// --- Prêt ----------------------------------------------------------------------------
	runButton.disabled = false;
	$('.overlay').classList.add('hidden');
	mark('ready');
	mesurer('exercice-ouvert', { ...mesure, compte: config.progress.mode === 'api' });
	status(`Prêt en ${(metrics.ready / 1000).toFixed(1)} s`, 'ok');
	Object.assign(window, { playground: { metrics, runtime, editor: editor.instance, monaco } }); // debug / mesures
}

function layout(exercise: ExercisePayload, config: PlaygroundConfig): string {
	const framework = exercise.environment.framework;
	const consoleName = framework.console;
	return `
	<header class="topbar">
		<div class="crumbs">
			<a class="lp-brand" href="/" title="${escapeHtml(config.brand.title)}">${config.brand.logoUrl ? `<img src="${escapeHtml(config.brand.logoUrl)}" alt="" width="240" height="280">` : ''}<span class="lp-serif">${escapeHtml(config.brand.name)}</span>${config.brand.chip ? `<span class="lp-chip">${escapeHtml(config.brand.chip)}</span>` : ''}</a>
			<span class="sep">›</span><a class="crumb-track" href="${escapeHtml(config.back.url)}">${escapeHtml(config.back.title)}</a><span class="sep crumb-track">›</span><span class="crumb-current">${escapeHtml(exercise.title)}</span><span class="done-chip" id="done-chip" hidden title="Exercice réussi">✓ Réussi</span>
		</div>
		<nav class="pane-switch" aria-label="Volet affiché">
			<button data-pane="brief">Consignes</button>
			<button data-pane="code" class="active">Code</button>
			<button data-pane="preview">Aperçu</button>
		</nav>
		<div class="status">Démarrage…</div>
		${
			config.user
				? `<div class="who" title="Connecté·e"><span>👤 ${escapeHtml(config.user.name)}</span><span class="xp-badge" id="user-xp">⭐ ${config.user.xp} XP</span></div>`
				: `<div class="who guest" title="Progression enregistrée dans ce navigateur"><span>Invité</span>${config.loginUrl ? `<a href="${escapeHtml(config.loginUrl)}">Connexion</a>` : ''}</div>`
		}
		<div class="actions">
			${config.feedbackUrl ? `<button id="feedback" class="ghost" title="Signaler un problème ou donner votre avis sur cet exercice"><span class="icon">💬</span><span class="label"> Un avis ?</span></button>` : ''}
			<button id="solution" class="ghost" hidden><span class="icon">📖</span><span class="label">Solution</span></button>
			<button id="reset" class="ghost" title="Revenir au code de départ"><span class="icon">⟲</span><span class="label">Réinitialiser</span></button>
			<button id="run" class="primary" disabled>▶ <span class="label">Lancer les </span>tests</button>
		</div>
	</header>
	<div class="small-screen-note" role="note"><span>Cet exercice se joue mieux sur un écran large : ici, un volet à la fois.</span><button class="ghost small" aria-label="Fermer">✕</button></div>
	<main class="workspace" data-pane="code">
		<aside class="panel brief">
			<div class="already-done" id="already-done" hidden></div>
			<div class="xp">${exercise.concepts.map((c) => `<span class="chip">${escapeHtml(c)}</span>`).join('')}${exercise.xp > 0 ? `<span class="chip gold">${exercise.xp} XP</span>` : ''}${exercise.practice?.version ? (exercise.practice.versionUrl ? `<a class="chip gold" href="${escapeHtml(exercise.practice.versionUrl)}" title="Les autres exercices de cette version">${escapeHtml(framework.label)} ${escapeHtml(exercise.practice.version)}</a>` : `<span class="chip gold">${escapeHtml(framework.label)} ${escapeHtml(exercise.practice.version)}</span>`) : ''}</div>
			<article class="instructions">${markdown(exercise.instructions)}</article>
			<h3>Objectifs</h3>
			<ul class="objectives">
				${exercise.objectives.map((o) => `<li data-test="${escapeHtml(o.test)}"><span class="dot"></span><span>${escapeHtml(o.label)}</span></li>`).join('')}
			</ul>
			${
				exercise.docs.length
					? `<h3>📚 La doc qui aide</h3>
			<ul class="docs">${exercise.docs.map((d) => `<li><a href="${escapeHtml(d.url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(d.title)}</a> <span class="host">${escapeHtml(new URL(d.url).hostname.replace(/^www\./, ''))}</span></li>`).join('')}</ul>`
					: ''
			}
			<div id="explain" class="mentor" hidden></div>
			<div id="success" class="success" hidden></div>
			<div id="review" class="mentor" hidden></div>
			<div id="solution-note" class="solution-note" hidden></div>
			<div class="hints" id="hints"></div>
			<button id="hint" class="ghost small">💡 Un indice ?</button>
			<details class="output" id="output-box" hidden><summary>Sortie ${'vitest' === framework.testRunner ? 'Vitest' : 'PHPUnit'} <button id="explain-tests" class="ghost small" hidden title="Demander au mentor ce que signifie cette erreur">🩺 Expliquer l'erreur</button></summary><pre id="output"></pre></details>
		</aside>
		<section class="panel code">
			<nav class="tabs">
				<button id="toggle-tree" class="tree-toggle open" title="Afficher ou masquer les fichiers du projet" aria-label="Fichiers du projet">📁</button>
				<span class="tabs-files" id="tabs"></span>
			</nav>
			<div class="code-body">
				<aside class="filetree" id="filetree">
					<div class="filetree-head">Projet <button id="new-file" class="filetree-new" type="button" title="Créer un fichier" aria-label="Créer un fichier" hidden>＋</button></div>
					<div id="filetree-body"></div>
				</aside>
				<div class="editor" id="editor"></div>
			</div>
		</section>
		<section class="panel preview">
			<nav class="pane-tabs">
				<button class="active" data-pane="preview">Aperçu</button>
				<button data-pane="console">Console <span class="badge" hidden title="Une commande a échoué">!</span></button>
				<button data-pane="requests">Requêtes</button>
			</nav>
			<div class="pane-preview">
				<div class="urlbar">
					<span class="origin" title="L'application tourne dans un bac à sable isolé">🔒 aperçu</span>
					<input id="url" value="${escapeHtml(exercise.preview)}" spellcheck="false" aria-label="Chemin de l'aperçu" />
					<button id="reload" class="ghost small" title="Recharger">⟳</button>
				</div>
				<!-- Sans allow-top-navigation : le code de l'apprenant ne peut pas rediriger la plateforme.
				     allow-same-origin garde au relais son origine bac à sable, sans quoi il n'enregistre pas le Service Worker. -->
				<div class="preview-frozen" id="preview-frozen" role="alert" hidden>La page ne répondait plus : boucle infinie dans son code, ou boîte de dialogue restée ouverte ? L'aperçu a été relancé sans la recharger. Corrigez la boucle, puis rechargez (⟳).</div>
				<iframe id="frame" title="Aperçu de l'application" sandbox="allow-scripts allow-same-origin allow-forms allow-modals allow-popups allow-downloads"></iframe>
				<div class="requestlog"><span id="requestlog"></span><button id="explain-preview" class="ghost small" hidden title="Demander au mentor ce que signifie cette erreur">🩺 Expliquer l'erreur</button></div>
			</div>
			<div class="pane-console" hidden>
				<div class="console-output" id="console-output"></div>
				<label class="console-input"><span class="prompt">$ ${consoleName}</span><input id="console-cmd" spellcheck="false" autocomplete="off" placeholder="${escapeHtml(framework.consoleExample)}" aria-label="Commande ${consoleName}" /><button id="explain-console" class="ghost small" hidden title="Demander au mentor ce que signifie cette erreur">🩺 Expliquer</button></label>
			</div>
			<div class="pane-requests" hidden>
				<div class="req-examples" id="req-examples" hidden></div>
				<div class="req-line">
					<select id="req-method" aria-label="Méthode HTTP">${['GET', 'POST', 'PUT', 'PATCH', 'DELETE'].map((m) => `<option>${m}</option>`).join('')}</select>
					<input id="req-path" value="/" spellcheck="false" aria-label="Chemin de la requête" />
					<button id="req-send" class="primary small" title="Entrée dans le chemin, ou Ctrl/⌘ + Entrée">Envoyer</button>
				</div>
				<textarea id="req-headers" rows="2" spellcheck="false" placeholder="En-têtes, un par ligne (ex. Authorization: Bearer …)" aria-label="En-têtes"></textarea>
				<textarea id="req-body" rows="5" spellcheck="false" placeholder='Corps JSON (ex. {"nom": "Gimli"})' aria-label="Corps de la requête"></textarea>
				<div class="req-response">
					<div class="req-status" id="req-status">Réponse</div>
					<details><summary>En-têtes de la réponse</summary><pre id="req-response-headers"></pre></details>
					<pre class="req-body" id="req-response-body"></pre>
				</div>
			</div>
		</section>
	</main>
	<div class="overlay">
		<div class="boot">
			<div class="logo">🐉</div>
			<p id="boot-label">Préparation de l'environnement…</p>
			<div class="bar"><div id="boot-bar"></div></div>
			<small>${escapeHtml(framework.bootNote)}</small>
		</div>
	</div>`;
}
