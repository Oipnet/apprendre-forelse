import { m } from '../i18n';
import { foldButtonHtml, railHtml } from './folding';
import { escapeHtml } from './html';
import { foldShortcutLabel } from './shortcuts';
import { sanitize } from './markdown';
import type { ExercisePayload, PlaygroundConfig } from './types';

/** Le squelette HTML de l'environnement d'exercice : consignes, code, aperçu, écran de démarrage. */
export function layout(exercise: ExercisePayload, config: PlaygroundConfig, platform = navigator.platform): string {
	const framework = exercise.environment.framework;
	const consoleName = framework.console;
	const foldBrief = foldShortcutLabel(platform, 'B');
	const foldPreview = foldShortcutLabel(platform, 'P');
	return `
	<header class="topbar">
		<div class="crumbs">
			<a class="lp-brand" href="/" title="${escapeHtml(config.brand.title)}">${config.brand.logoUrl ? `<img src="${escapeHtml(config.brand.logoUrl)}" alt="" width="240" height="280">` : ''}<span class="lp-serif">${escapeHtml(config.brand.name)}</span>${config.brand.chip ? `<span class="lp-chip">${escapeHtml(config.brand.chip)}</span>` : ''}</a>
			<span class="sep">›</span><a class="crumb-track" href="${escapeHtml(config.back.url)}">${escapeHtml(config.back.title)}</a><span class="sep crumb-track">›</span>${crumbs(exercise)}<span class="done-chip" id="done-chip" hidden title="${m.layout.doneChipTitle}"><span aria-hidden="true">✓</span> ${m.layout.doneChip}</span>
		</div>
		<nav class="pane-switch" aria-label="${m.layout.paneSwitch}">
			<button data-pane="brief">${m.layout.brief}</button>
			<button data-pane="code" class="active">${m.layout.code}</button>
			<button data-pane="preview">${m.layout.preview}</button>
		</nav>
		<div class="status" role="status">${m.layout.starting}</div>
		${
			config.user
				? `<div class="who" title="${m.layout.userTitle}"><span><span aria-hidden="true">👤</span> ${escapeHtml(config.user.name)}</span><span class="xp-badge" id="user-xp">${xpBadge(config.user.xp)}</span></div>`
				: `<div class="who guest" title="${m.layout.guestTitle}"><span>${m.layout.guest}</span>${config.loginUrl ? `<a href="${escapeHtml(config.loginUrl)}">${m.layout.login}</a>` : ''}</div>`
		}
		<div class="actions">
			${config.share ? `<button id="share" class="ghost" title="${m.layout.shareTitle}"><span class="icon" aria-hidden="true">🔗</span><span class="label"> ${m.layout.share}</span></button>` : ''}
			${config.feedbackUrl ? `<button id="feedback" class="ghost" title="${m.layout.feedbackTitle}"><span class="icon" aria-hidden="true">💬</span><span class="label"> ${m.layout.feedback}</span></button>` : ''}
			<button id="solution" class="ghost" hidden><span class="icon" aria-hidden="true">📖</span><span class="label">${m.layout.solution}</span></button>
			<button id="reset" class="ghost" title="${m.layout.resetTitle}"><span class="icon" aria-hidden="true">⟲</span><span class="label">${m.layout.reset}</span></button>
			<button id="shortcuts" class="ghost" title="${m.layout.shortcuts}" aria-label="${m.layout.shortcuts}" aria-haspopup="dialog"><span aria-hidden="true">⌨</span></button>
			<button id="run" class="primary" disabled aria-keyshortcuts="Control+Enter Meta+Enter">▶ ${m.layout.run((texte) => `<span class="label">${texte}</span>`)}</button>
		</div>
	</header>
	<div class="small-screen-note" role="note"><span>${m.layout.smallScreen}</span><button class="ghost small" aria-label="${m.common.close}">✕</button></div>
	<main class="workspace" data-pane="code">
		<aside class="panel brief" id="pane-brief" aria-label="${m.layout.brief}">
			${railHtml('brief', '📋', m.layout.brief, foldBrief)}
			${foldButtonHtml('brief', foldBrief)}
			<div class="already-done" id="already-done" hidden></div>
			${exercise.practice ? `<p class="lead">${escapeHtml(exercise.practice.summary)}</p>` : ''}
			<div class="xp">${exercise.concepts.map((c) => conceptChip(c, exercise.conceptUrls[c])).join('')}${exercise.xp > 0 ? `<span class="chip gold">${m.common.xp(exercise.xp)}</span>` : ''}${exercise.practice?.version ? (exercise.practice.versionUrl ? `<a class="chip gold" href="${escapeHtml(exercise.practice.versionUrl)}" title="${m.layout.versionTitle}">${escapeHtml(framework.label)} ${escapeHtml(exercise.practice.version)}</a>` : `<span class="chip gold">${escapeHtml(framework.label)} ${escapeHtml(exercise.practice.version)}</span>`) : ''}</div>
			<article class="instructions">${sanitize(exercise.instructionsHtml)}</article>
			<h3>${m.layout.objectives}</h3>
			<ul class="objectives">
				${exercise.objectives.map((o) => `<li data-test="${escapeHtml(o.test)}"><span class="dot" aria-hidden="true"></span><span class="sr-only objective-state"></span><span>${escapeHtml(o.label)}</span></li>`).join('')}
			</ul>
			${
				exercise.docs.length
					? `<h3><span aria-hidden="true">📚</span> ${m.layout.docs}</h3>
			<ul class="docs">${exercise.docs.map((d) => `<li><a href="${escapeHtml(d.url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(d.title)}</a> <span class="host">${escapeHtml(new URL(d.url).hostname.replace(/^www\./, ''))}</span></li>`).join('')}</ul>`
					: ''
			}
			${furtherHtml(exercise, config)}
			<div id="explain" class="mentor" hidden></div>
			<div id="success" class="success" hidden></div>
			<div id="review" class="mentor" hidden></div>
			<div id="solution-note" class="solution-note" hidden></div>
			<div class="hints" id="hints"></div>
			<button id="hint" class="ghost small"><span aria-hidden="true">💡</span> ${m.hints.hint(0)}</button>
			<details class="output" id="output-box" hidden><summary>${m.layout.testOutput(escapeHtml(framework.testRunnerLabel))} <button id="explain-tests" class="ghost small" hidden title="${m.layout.explainTitle}"><span aria-hidden="true">🩺</span> ${m.layout.explainError}</button></summary><pre id="output"></pre></details>
		</aside>
		<section class="panel code">
			<nav class="tabs">
				<button id="toggle-tree" class="tree-toggle open" title="${m.layout.treeTitle}" aria-label="${m.layout.tree}" aria-controls="filetree" aria-expanded="true">📁</button>
				<span class="tabs-files" id="tabs"></span>
			</nav>
			<div class="code-body">
				<aside class="filetree" id="filetree">
					<div class="filetree-head">${m.layout.project} <button id="new-file" class="filetree-new" type="button" title="${m.layout.newFile}" aria-label="${m.layout.newFile}" hidden>＋</button></div>
					<div id="filetree-body"></div>
				</aside>
				<div class="editor" id="editor"></div>
			</div>
		</section>
		<section class="panel preview" id="pane-preview" aria-label="${m.layout.preview}">
			${railHtml('preview', '👁', m.layout.preview, foldPreview)}
			<nav class="pane-tabs">
				<button class="active" data-pane="preview">${m.layout.preview}</button>
				<button data-pane="console">${m.layout.console} <span class="badge" hidden title="${m.layout.commandFailed}">!</span></button>
				<button data-pane="requests">${m.layout.requests}</button>
				${foldButtonHtml('preview', foldPreview)}
			</nav>
			<div class="pane-preview">
				<div class="urlbar">
					<span class="origin" title="${m.layout.sandboxTitle}"><span aria-hidden="true">🔒</span> ${m.layout.sandbox}</span>
					<input id="url" value="${escapeHtml(exercise.preview)}" spellcheck="false" aria-label="${m.layout.previewPath}" />
					<button id="reload" class="ghost small" title="${m.layout.reloadTitle}" aria-label="${m.layout.reload}">⟳</button>
				</div>
				<!-- Sans allow-top-navigation : le code de l'apprenant ne peut pas rediriger la plateforme.
				     allow-same-origin garde au relais son origine bac à sable, sans quoi il n'enregistre pas le Service Worker. -->
				<div class="preview-frozen" id="preview-frozen" role="alert" hidden>${m.layout.previewFrozen}</div>
				<iframe id="frame" title="${m.layout.previewFrame}" sandbox="allow-scripts allow-same-origin allow-forms allow-modals allow-popups allow-downloads"></iframe>
				<div class="requestlog"><span id="requestlog"></span><button id="explain-preview" class="ghost small" hidden title="${m.layout.explainTitle}"><span aria-hidden="true">🩺</span> ${m.layout.explainError}</button></div>
				<!-- Le démarrage ne couvre que l'aperçu : consignes et code se lisent, et s'éditent, pendant le chargement. -->
				<div class="overlay">
					<div class="boot">
						<div class="logo">🐉</div>
						<p id="boot-label" role="status">${m.layout.bootPreparing}</p>
						<div class="bar"><div id="boot-bar"></div></div>
						<small>${escapeHtml(framework.bootNote)}</small>
						<small id="boot-first" class="boot-first"></small>
					</div>
				</div>
			</div>
			<div class="pane-console" hidden>
				<div class="console-output" id="console-output"></div>
				<label class="console-input"><span class="prompt">$ ${consoleName}</span><input id="console-cmd" spellcheck="false" autocomplete="off" placeholder="${escapeHtml(framework.consoleExample)}" aria-label="${m.layout.consoleCommand(consoleName)}" /><button id="explain-console" class="ghost small" hidden title="${m.layout.explainTitle}">🩺 ${m.layout.explain}</button></label>
			</div>
			<div class="pane-requests" hidden>
				<div class="req-examples" id="req-examples" hidden></div>
				<div class="req-line">
					<select id="req-method" aria-label="${m.layout.requestMethod}">${['GET', 'POST', 'PUT', 'PATCH', 'DELETE'].map((method) => `<option>${method}</option>`).join('')}</select>
					<input id="req-path" value="/" spellcheck="false" aria-label="${m.layout.requestPath}" />
					<button id="req-send" class="primary small" title="${m.layout.requestSendTitle}">${m.common.send}</button>
				</div>
				<textarea id="req-headers" rows="2" spellcheck="false" placeholder="${m.layout.requestHeadersPlaceholder}" aria-label="${m.layout.requestHeaders}"></textarea>
				<textarea id="req-body" rows="5" spellcheck="false" placeholder='${m.layout.requestBodyPlaceholder}' aria-label="${m.layout.requestBody}"></textarea>
				<div class="req-response">
					<div class="req-status" id="req-status">${m.layout.response}</div>
					<details><summary>${m.layout.responseHeaders}</summary><pre id="req-response-headers"></pre></details>
					<pre class="req-body" id="req-response-body"></pre>
				</div>
			</div>
		</section>
	</main>`;
}

/**
 * Le fil d'Ariane après le parcours : chapitre, exercice, « n/N », et les exercices voisins. Sur petit écran, le chapitre
 * disparaît le premier (playground.css) ; la position et les flèches restent.
 */
export function crumbs(exercise: Pick<ExercisePayload, 'title' | 'chapter' | 'previous' | 'next'>): string {
	const { chapter, previous, next } = exercise;
	const label = (neighbour: NonNullable<ExercisePayload['next']>, rel: 'prev' | 'next') => (rel === 'prev' ? m.layout.previousExercise : m.layout.nextExercise)(escapeHtml(neighbour.title));
	const arrow = (neighbour: ExercisePayload['next'], rel: 'prev' | 'next') =>
		neighbour
			? `<a class="crumb-arrow" rel="${rel}" href="${escapeHtml(neighbour.url)}" title="${label(neighbour, rel)}" aria-label="${label(neighbour, rel)}">${rel === 'prev' ? '←' : '→'}</a>`
			: `<span class="crumb-arrow" aria-hidden="true">${rel === 'prev' ? '←' : '→'}</span>`;
	return [
		chapter
			? `<a class="crumb-chapter" href="${escapeHtml(chapter.url)}" title="${m.layout.chapterTitle(chapter.number, escapeHtml(chapter.title))}">${m.layout.chapterCrumb(chapter.number, escapeHtml(chapter.title))}</a><span class="sep crumb-chapter">›</span>`
			: '',
		// Le seul titre de la page une fois le playground monté : c'est lui que lisent les moteurs de recherche.
		`<h1 class="crumb-current">${escapeHtml(exercise.title)}</h1>`,
		chapter ? `<span class="crumb-position" title="${m.layout.position(chapter.position, chapter.total)}"><span aria-hidden="true">${chapter.position}/${chapter.total}</span><span class="sr-only">${m.layout.position(chapter.position, chapter.total)}</span></span>` : '',
		previous || next ? `<nav class="crumb-nav" aria-label="${m.layout.neighbours}">${arrow(previous, 'prev')}${arrow(next, 'next')}</nav>` : '',
	].join('');
}

/** Une notion : un lien vers sa page quand elle en a une, sinon une simple pastille. */
function conceptChip(concept: string, url: string | undefined): string {
	return url ? `<a class="chip" href="${escapeHtml(url)}">${escapeHtml(concept)}</a>` : `<span class="chip">${escapeHtml(concept)}</span>`;
}

/**
 * Pour aller plus loin, en Pratique : les autres nouveautés de la version, le parcours du framework et toute la Pratique.
 * Visibles dès l'arrivée, pas seulement après la réussite : un visiteur venu d'un lien partagé (et un moteur de
 * recherche) trouve où continuer.
 */
export function furtherHtml(exercise: Pick<ExercisePayload, 'practice' | 'environment'>, config: Pick<PlaygroundConfig, 'back'>): string {
	const practice = exercise.practice;
	if (!practice) {
		return '';
	}
	const links = [
		practice.versionUrl ? `<li><a href="${escapeHtml(practice.versionUrl)}">${m.layout.furtherVersion(escapeHtml(exercise.environment.framework.label), escapeHtml(practice.version ?? ''))}</a></li>` : '',
		practice.track ? `<li><a href="${escapeHtml(practice.track.url)}">${m.layout.furtherTrack(escapeHtml(practice.track.title))}</a></li>` : '',
		`<li><a href="${escapeHtml(config.back.url)}">${m.layout.furtherPractice}</a></li>`,
	];
	return `<h3>${m.layout.furtherTitle}</h3>
			<ul class="further">${links.join('')}</ul>`;
}

/** Le total d'XP de la barre : l'étoile est décorative, le nombre est lu. */
export const xpBadge = (xp: number) => `<span aria-hidden="true">⭐</span> ${m.common.xp(xp)}`;
