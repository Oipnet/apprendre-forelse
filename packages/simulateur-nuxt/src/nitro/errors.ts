/**
 * Réponse d'erreur de `nuxi dev` : le gestionnaire de Nuxt (@nuxt/nitro-server, handlers/error)
 * laisse les requêtes « JSON » au gestionnaire de développement de Nitro (internal/error/dev),
 * et rend une page HTML pour les autres.
 */
import { createError, isError, type H3Error } from '../h3/error.ts';
import type { H3Event } from '../h3/event.ts';
import { joinURL, withQuery, withoutBase } from 'ufo';
import { appendResponseHeader, getRequestHeader, getRequestURL, getResponseHeader, send, setResponseHeader, setResponseHeaders, setResponseStatus } from '../h3/utils.ts';

export interface ErrorPageRenderer {
	/** `app.baseURL` : retiré de l'URL de l'erreur, ajouté devant `/__nuxt_error`. */
	baseURL: string;
	/** Requête interne du gestionnaire d'erreur de Nuxt, servie par le simulateur lui-même. */
	fetch(url: string, headers: Record<string, string>): Promise<Response | null>;
}

/** Même logique que le bloc catch de `toNodeListener` (h3), suivi du gestionnaire d'erreur de Nitro puis de Nuxt. */
export async function respondWithError(thrown: unknown, event: H3Event, renderErrorPage?: ErrorPageRenderer): Promise<void> {
	const error = createError(thrown as Error);
	if (!isError(thrown)) {
		error.unhandled = true;
	}
	setResponseStatus(event, error.statusCode, error.statusMessage);
	if (event.handled) {
		return;
	}
	if (isJsonRequest(event)) {
		const response = defaultHandler(error, event, {});
		setResponseHeaders(event, response.headers);
		setResponseStatus(event, response.status, response.statusText);
		return send(event, JSON.stringify(response.body, null, 2));
	}
	return sendErrorPage(error, event, renderErrorPage);
}

export function isJsonRequest(event: H3Event): boolean {
	if (hasRequestHeader(event, 'accept', 'text/html')) {
		return false;
	}
	return hasRequestHeader(event, 'accept', 'application/json')
		|| hasRequestHeader(event, 'user-agent', 'curl/')
		|| hasRequestHeader(event, 'user-agent', 'httpie/')
		|| hasRequestHeader(event, 'sec-fetch-mode', 'cors')
		|| event.path.startsWith('/api/')
		|| event.path.endsWith('.json');
}

function hasRequestHeader(event: H3Event, name: string, includes: string): boolean {
	const value = getRequestHeader(event, name);
	return !!(value && value.toLowerCase().includes(includes));
}

interface ErrorResponse {
	status: number;
	statusText: string;
	headers: Record<string, string>;
	body: Record<string, unknown>;
}

function defaultHandler(error: H3Error, event: H3Event, opts: { json?: boolean }): ErrorResponse {
	const statusCode = error.statusCode || 500;
	const statusMessage = error.statusMessage || 'Server Error';
	const url = getRequestURL(event, { xForwardedHost: true, xForwardedProto: true });
	const useJSON = opts.json ?? !getRequestHeader(event, 'accept')?.includes('text/html');
	const headers: Record<string, string> = {
		'content-type': useJSON ? 'application/json' : 'text/html',
		'x-content-type-options': 'nosniff',
		'x-frame-options': 'DENY',
		'referrer-policy': 'no-referrer',
		'content-security-policy': "script-src 'self' 'unsafe-inline'; object-src 'none'; base-uri 'self';",
	};
	if (statusCode === 404 || !getResponseHeader(event, 'cache-control')) {
		headers['cache-control'] = 'no-cache';
	}
	return {
		status: statusCode,
		statusText: statusMessage,
		headers,
		body: {
			error: true,
			url,
			statusCode,
			statusMessage,
			message: error.message,
			data: error.data,
			stack: formatStack(error),
		},
	};
}

/**
 * Nitro réécrit la pile (message, puis une ligne « at … » par appel) avant de la découper en lignes.
 * Les chemins ne peuvent pas être ceux d'un vrai serveur : seule la forme est imitée.
 */
function formatStack(error: H3Error): string[] {
	const frames = (error.stack ?? '').split('\n').map((line) => line.trim()).filter((line) => line.startsWith('at '));
	return [...error.message.split('\n'), ...frames];
}

/**
 * @nuxt/nitro-server (handlers/error) : Nuxt rend sa page d'erreur par une requête interne à `/__nuxt_error`,
 * l'erreur passée dans la query (les valeurs y deviennent des chaînes). En développement, Nuxt ajoute
 * ensuite sa surcouche d'erreur avant `</body>` : le simulateur s'en passe.
 */
async function sendErrorPage(error: H3Error, event: H3Event, renderErrorPage?: ErrorPageRenderer): Promise<void> {
	const defaultRes = defaultHandler(error, event, { json: true });
	const errorObject = defaultRes.body as Record<string, any>;
	errorObject.stack = (errorObject.stack as string[]).join('\n');
	const url = new URL(errorObject.url as string);
	errorObject.url = withoutBase(url.pathname, renderErrorPage?.baseURL ?? '/') + url.search + url.hash;
	errorObject.message = error.unhandled ? errorObject.message || 'Server Error' : error.message || errorObject.message || 'Server Error';
	errorObject.data ||= error.data;
	errorObject.statusText ||= (error as { statusText?: string }).statusText || error.statusMessage;
	const { 'content-type': _type, 'content-security-policy': _csp, ...headers } = defaultRes.headers;
	setResponseHeaders(event, headers);
	const requestHeaders = { ...event.node.req.headers };
	const res = event.path.startsWith('/__nuxt_error') || requestHeaders['x-nuxt-error'] || !renderErrorPage
		? null
		: await renderErrorPage.fetch(withQuery(joinURL(renderErrorPage.baseURL, '/__nuxt_error'), errorObject), { ...requestHeaders, 'x-nuxt-error': 'true' }).catch(() => null);
	if (event.handled) {
		return;
	}
	if (!res) {
		setResponseHeader(event, 'content-type', 'text/html;charset=utf-8');
		setResponseStatus(event, defaultRes.status, defaultRes.statusText);
		const message = errorObject.message as string;
		return send(event, `<!DOCTYPE html><html><head><meta charset="utf-8"><title>${defaultRes.status} - ${escapeHtml(message)} | Nuxt</title></head><body><h1>${defaultRes.status}</h1><p>${escapeHtml(message)}</p></body></html>`);
	}
	const html = await res.text();
	res.headers.forEach((value, header) => {
		if (header === 'set-cookie') {
			appendResponseHeader(event, header, value);
			return;
		}
		if (header === 'content-length') return;
		setResponseHeader(event, header, value);
	});
	// Réponse interne en 200 : sa raison est vide chez Nitro, c'est celle de l'erreur qui compte.
	setResponseStatus(event, res.status && res.status !== 200 ? res.status : defaultRes.status, res.status !== 200 ? res.statusText : defaultRes.statusText);
	return send(event, html);
}

function escapeHtml(text: string): string {
	return text.replace(/[&<>"']/g, (char) => `&#${char.charCodeAt(0)};`);
}
