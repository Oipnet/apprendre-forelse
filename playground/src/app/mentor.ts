/**
 * Le mentor : une revue de code quand les tests passent, une explication quand une erreur survient.
 * Contrat serveur : App\Controller\Api\MentorApiController (comptes seulement, débit limité).
 */

export interface Review {
	summary: string;
	points: { file: string; line: number | null; message: string; snippet: string | null }[];
}

export interface Explanation {
	cause: string;
	piste: string;
	/** Le fichier de l'apprenant probablement en cause, parmi les éditables. */
	file: string | null;
}

export type ErrorSource = 'preview' | 'tests' | 'console';

export interface MentorUrls {
	reviewUrl: string;
	explainUrl: string;
}

const escapeHtml = (s: string) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!);

export class MentorClient {
	constructor(private readonly urls: MentorUrls) {}

	review(files: Record<string, string>): Promise<Review> {
		return this.post<Review>(this.urls.reviewUrl, { files });
	}

	explain(files: Record<string, string>, error: string, source: ErrorSource): Promise<Explanation> {
		return this.post<Explanation>(this.urls.explainUrl, { files, error: error.slice(0, 20000), source });
	}

	private async post<T>(url: string, body: unknown): Promise<T> {
		const response = await fetch(url, {
			method: 'POST',
			headers: { accept: 'application/json', 'content-type': 'application/json' },
			body: JSON.stringify(body),
			credentials: 'same-origin',
		});
		// Session expirée (déconnexion, page restée ouverte) : le serveur de production ne détaille pas ce refus.
		if (response.status === 401) throw new Error('Votre session a expiré : reconnectez-vous, puis rechargez la page.');
		if (response.status === 403) throw new Error('Votre accès à ce parcours a pris fin : le mentor n\'est plus disponible pour cet exercice.');
		if (!response.ok) {
			// Le serveur explique ses refus (débit dépassé, mentor absent…) dans `detail`.
			const detail = await response.json().then((e: { detail?: string }) => e.detail).catch(() => undefined);
			throw new Error(detail || `réponse ${response.status}`);
		}
		return (await response.json()) as T;
	}
}

/** Rend la revue en HTML ; `markdown` nettoie et convertit le markdown léger du modèle. */
export function renderReview(review: Review, markdown: (source: string) => string): string {
	const points = review.points
		.map(
			(p) => `<li>
				${p.file ? `<span class="where"><a href="#" data-open="${escapeHtml(p.file)}" data-line="${p.line ?? ''}">${escapeHtml(p.file.split('/').pop()!)}${p.line ? `:${p.line}` : ''}</a></span>` : ''}
				<div class="what">${markdown(p.message)}</div>
				${p.snippet ? `<pre>${escapeHtml(p.snippet)}</pre>` : ''}
			</li>`,
		)
		.join('');
	return `<strong>🔍 Revue de code</strong>
		<div class="what">${markdown(review.summary)}</div>
		${points ? `<ol class="review-points">${points}</ol>` : '<p class="muted">Rien à redire : c\'est propre.</p>'}`;
}

export function renderExplanation(explanation: Explanation, markdown: (source: string) => string): string {
	return `<strong>🩺 Le mentor</strong>
		<div class="what">${markdown(explanation.cause)}</div>
		<div class="what"><b>Piste :</b> ${markdown(explanation.piste)}</div>
		${explanation.file ? `<span class="where">Regardez du côté de <a href="#" data-open="${escapeHtml(explanation.file)}">${escapeHtml(explanation.file)}</a>.</span>` : ''}`;
}

/**
 * Le texte utile d'une réponse d'aperçu en erreur : la page d'exception de Symfony est un gros
 * HTML dont seuls le message, la classe et les premières lignes de trace intéressent le mentor.
 */
export function errorTextOf(body: Uint8Array, contentType = ''): string {
	const text = new TextDecoder().decode(body);
	if (!/html/i.test(contentType) && !/^\s*</.test(text)) return text.slice(0, 6000);
	const doc = new DOMParser().parseFromString(text, 'text/html');
	const parts: string[] = [];
	const title = doc.querySelector('title')?.textContent?.trim();
	if (title) parts.push(title);
	// Page d'exception de Laravel (debug) : classe dans le <h1>, message dans le <p> qui suit, fichiers des cadres en infobulles.
	const laravelClass = doc.querySelector('h1.text-3xl')?.textContent?.trim();
	const laravelMessage = doc.querySelector('p.text-xl')?.textContent?.trim();
	if (laravelClass && laravelMessage) {
		parts.push(`${laravelClass}: ${laravelMessage}`);
		const frames = [...doc.querySelectorAll('[data-tippy-content]')].map((e) => e.getAttribute('data-tippy-content')?.trim()).filter(Boolean);
		if (frames.length) parts.push(frames.slice(0, 20).join('\n'));
	}
	// Page d'exception de Symfony (dev) : message, classe, puis la trace ; sinon le texte brut.
	const message = doc.querySelector('.exception-message-wrapper, .exception-message')?.textContent?.trim();
	if (message) parts.push(message);
	const trace = doc.querySelector('.trace, #trace-box-1, .traces')?.textContent?.replace(/\s+\n/g, '\n').trim();
	if (trace) parts.push(trace.split('\n').filter((l) => l.trim()).slice(0, 25).join('\n'));
	if (parts.length < 2) parts.push((doc.body?.textContent ?? text).replace(/[ \t]+/g, ' ').replace(/\n{2,}/g, '\n').trim());
	return parts.join('\n\n').slice(0, 6000);
}

/** Enlève les couleurs ANSI d'une sortie de console. */
export const stripAnsi = (s: string) => s.replace(/\x1b\[[\d;]*[A-Za-z]/g, '');
