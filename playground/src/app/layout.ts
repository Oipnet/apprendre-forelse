import { escapeHtml } from './html';
import { markdown } from './markdown';
import type { ExercisePayload, PlaygroundConfig } from './types';

/** Le squelette HTML de l'environnement d'exercice : consignes, code, aperçu, écran de démarrage. */
export function layout(exercise: ExercisePayload, config: PlaygroundConfig): string {
	const framework = exercise.environment.framework;
	const consoleName = framework.console;
	return `
	<header class="topbar">
		<div class="crumbs">
			<a class="lp-brand" href="/" title="${escapeHtml(config.brand.title)}">${config.brand.logoUrl ? `<img src="${escapeHtml(config.brand.logoUrl)}" alt="" width="240" height="280">` : ''}<span class="lp-serif">${escapeHtml(config.brand.name)}</span>${config.brand.chip ? `<span class="lp-chip">${escapeHtml(config.brand.chip)}</span>` : ''}</a>
			<span class="sep">›</span><a class="crumb-track" href="${escapeHtml(config.back.url)}">${escapeHtml(config.back.title)}</a><span class="sep crumb-track">›</span>${crumbs(exercise)}<span class="done-chip" id="done-chip" hidden title="Exercice réussi">✓ Réussi</span>
		</div>
		<nav class="pane-switch" aria-label="Volet affiché">
			<button data-pane="brief">Consignes</button>
			<button data-pane="code" class="active">Code</button>
			<button data-pane="preview">Aperçu</button>
		</nav>
		<div class="status" role="status">Démarrage…</div>
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
				${exercise.objectives.map((o) => `<li data-test="${escapeHtml(o.test)}"><span class="dot" aria-hidden="true"></span><span class="sr-only objective-state"></span><span>${escapeHtml(o.label)}</span></li>`).join('')}
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
			<details class="output" id="output-box" hidden><summary>Sortie ${escapeHtml(framework.testRunnerLabel)} <button id="explain-tests" class="ghost small" hidden title="Demander au mentor ce que signifie cette erreur">🩺 Expliquer l'erreur</button></summary><pre id="output"></pre></details>
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
			<p id="boot-label" role="status">Préparation de l'environnement…</p>
			<div class="bar"><div id="boot-bar"></div></div>
			<small>${escapeHtml(framework.bootNote)}</small>
		</div>
	</div>`;
}

/**
 * Le fil d'Ariane après le parcours : chapitre, exercice, « n/N », et les exercices voisins. Sur petit écran, le chapitre
 * disparaît le premier (playground.css) ; la position et les flèches restent.
 */
export function crumbs(exercise: Pick<ExercisePayload, 'title' | 'chapter' | 'previous' | 'next'>): string {
	const { chapter, previous, next } = exercise;
	const arrow = (neighbour: ExercisePayload['next'], rel: 'prev' | 'next') =>
		neighbour
			? `<a class="crumb-arrow" rel="${rel}" href="${escapeHtml(neighbour.url)}" title="${rel === 'prev' ? 'Exercice précédent' : 'Exercice suivant'} : ${escapeHtml(neighbour.title)}" aria-label="${rel === 'prev' ? 'Exercice précédent' : 'Exercice suivant'} : ${escapeHtml(neighbour.title)}">${rel === 'prev' ? '←' : '→'}</a>`
			: `<span class="crumb-arrow" aria-hidden="true">${rel === 'prev' ? '←' : '→'}</span>`;
	return [
		chapter
			? `<a class="crumb-chapter" href="${escapeHtml(chapter.url)}" title="Chapitre ${chapter.number} : ${escapeHtml(chapter.title)}">Ch. ${chapter.number} · ${escapeHtml(chapter.title)}</a><span class="sep crumb-chapter">›</span>`
			: '',
		`<span class="crumb-current">${escapeHtml(exercise.title)}</span>`,
		chapter ? `<span class="crumb-position" title="Exercice ${chapter.position} sur ${chapter.total} du chapitre"><span aria-hidden="true">${chapter.position}/${chapter.total}</span><span class="sr-only">Exercice ${chapter.position} sur ${chapter.total} du chapitre</span></span>` : '',
		previous || next ? `<nav class="crumb-nav" aria-label="Exercices voisins">${arrow(previous, 'prev')}${arrow(next, 'next')}</nav>` : '',
	].join('');
}
