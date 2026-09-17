import type { HttpResponse, Runtime } from '../runtime/Runtime';
import type { ExampleRequest } from './types';

/** {{ date:+N }} → date du jour + N jours (AAAA-MM-JJ), pour des exemples qui ne se périment pas. */
export function expandPlaceholders(text: string, today = new Date()): string {
	return text.replace(/\{\{\s*date:([+-]?\d+)\s*\}\}/g, (_, days: string) => {
		const date = new Date(today.getFullYear(), today.getMonth(), today.getDate() + Number(days));
		return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
	});
}

/** « Nom: valeur » par ligne → objet (les lignes vides ou sans « : » sont ignorées). */
export function parseHeaders(text: string): Record<string, string> {
	const headers: Record<string, string> = {};
	for (const line of text.split('\n')) {
		const i = line.indexOf(':');
		if (i > 0) headers[line.slice(0, i).trim().toLowerCase()] = line.slice(i + 1).trim();
	}
	return headers;
}

const header = (response: HttpResponse, name: string) =>
	Object.entries(response.headers).find(([key]) => key.toLowerCase() === name)?.[1]?.join(', ') ?? '';

/** Corps lisible : JSON indenté quand c'est du JSON, texte brut sinon. */
export function formatBody(body: string, contentType: string): string {
	if (/json/i.test(contentType) || /^\s*[[{]/.test(body)) {
		try {
			return JSON.stringify(JSON.parse(body), null, 2);
		} catch {
			// JSON invalide : on montre tel quel.
		}
	}
	return body;
}

/**
 * Onglet « Requêtes » : un petit client HTTP vers l'application de l'apprenant.
 *
 * Les requêtes vont directement au runtime PHP (pas par l'aperçu) ; la réponse n'est
 * jamais interprétée : elle est affichée en texte, sans risque pour la plateforme.
 */
export class RequestsPanel {
	private readonly method: HTMLSelectElement;
	private readonly path: HTMLInputElement;
	private readonly headers: HTMLTextAreaElement;
	private readonly body: HTMLTextAreaElement;
	private readonly send: HTMLButtonElement;
	private readonly status: HTMLElement;
	private readonly responseHeaders: HTMLElement;
	private readonly responseBody: HTMLElement;

	constructor(
		root: HTMLElement,
		examples: ExampleRequest[],
		private readonly runtime: Runtime,
		/** Préfixe d'aperçu et en-tête Host, pour que l'application génère les bonnes URL. */
		private readonly base: string,
		private readonly host: string,
		/** Appelé avant chaque envoi (ex. écrire les dernières frappes dans PHP). */
		private readonly beforeSend: () => Promise<unknown> = async () => undefined,
	) {
		const $ = <T extends HTMLElement>(selector: string) => root.querySelector<T>(selector)!;
		this.method = $('#req-method');
		this.path = $('#req-path');
		this.headers = $('#req-headers');
		this.body = $('#req-body');
		this.send = $('#req-send');
		this.status = $('#req-status');
		this.responseHeaders = $('#req-response-headers');
		this.responseBody = $('#req-response-body');

		const list = $('#req-examples');
		for (const example of examples) {
			const button = document.createElement('button');
			button.className = 'ghost small';
			button.innerHTML = `<span class="method">${example.method}</span> `;
			button.append(example.title);
			button.title = `${example.method} ${example.path}`;
			button.addEventListener('click', () => this.fill(example));
			list.append(button);
		}
		list.hidden = examples.length === 0;
		if (examples[0]) this.fill(examples[0]);

		this.send.addEventListener('click', () => void this.run());
		for (const field of [this.path, this.headers, this.body] as HTMLElement[]) {
			field.addEventListener('keydown', (e: KeyboardEvent) => {
				if ((e.key === 'Enter' && field === this.path) || (e.key === 'Enter' && (e.metaKey || e.ctrlKey))) {
					e.preventDefault();
					void this.run();
				}
			});
		}
	}

	fill(example: ExampleRequest) {
		this.method.value = example.method;
		this.path.value = example.path;
		this.headers.value = Object.entries(example.headers).map(([name, value]) => `${name}: ${value}`).join('\n');
		this.body.value = example.body === null ? '' : expandPlaceholders(example.body);
	}

	async run() {
		if (this.send.disabled) return;
		this.send.disabled = true;
		this.status.textContent = 'Envoi…';
		this.status.dataset.kind = 'busy';
		try {
			await this.beforeSend();
			const path = this.path.value.trim() || '/';
			const body = this.body.value.trim();
			const headers: Record<string, string> = { accept: 'application/json', ...parseHeaders(this.headers.value), host: this.host };
			if (body && !headers['content-type']) headers['content-type'] = 'application/json';
			const response = await this.runtime.request({
				method: this.method.value,
				url: this.base + (path.startsWith('/') ? path : `/${path}`),
				headers,
				body: body && this.method.value !== 'GET' ? new TextEncoder().encode(body) : undefined,
			});
			const text = new TextDecoder().decode(response.body);
			const contentType = header(response, 'content-type');
			this.status.textContent = `${response.status} · ${Math.round(response.durationMs)} ms${contentType ? ` · ${contentType.split(';')[0]}` : ''}`;
			this.status.dataset.kind = response.status < 400 ? 'ok' : 'ko';
			this.responseHeaders.textContent = Object.entries(response.headers)
				.map(([name, values]) => values.map((v) => `${name}: ${v}`).join('\n'))
				.join('\n')
				.replaceAll(this.base, '');
			this.responseBody.textContent = formatBody(text, contentType) || '(corps vide)';
		} catch (error) {
			this.status.textContent = 'Erreur';
			this.status.dataset.kind = 'ko';
			this.responseBody.textContent = String(error);
		} finally {
			this.send.disabled = false;
		}
	}
}
