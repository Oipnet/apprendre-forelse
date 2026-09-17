/**
 * Réponse d'erreur de `nuxi dev` : le gestionnaire de Nuxt (@nuxt/nitro-server, handlers/error)
 * laisse les requêtes « JSON » au gestionnaire de développement de Nitro (internal/error/dev),
 * et rend une page HTML pour les autres.
 */
import { createError, isError, type H3Error } from '../h3/error.ts';
import type { H3Event } from '../h3/event.ts';
import { getRequestHeader, getRequestURL, getResponseHeader, send, setResponseHeader, setResponseHeaders, setResponseStatus } from '../h3/utils.ts';

/** Même logique que le bloc catch de `toNodeListener` (h3), suivi du gestionnaire d'erreur de Nitro. */
export async function respondWithError(thrown: unknown, event: H3Event): Promise<void> {
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
	return sendErrorPage(error, event);
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
 * Nuxt rend sa page d'erreur (app/error.vue ou celle par défaut) avec la surcouche de développement.
 * Le simulateur n'a pas encore de rendu Vue : une page minimale, avec le statut et les en-têtes de Nuxt.
 */
function sendErrorPage(error: H3Error, event: H3Event): Promise<void> {
	const response = defaultHandler(error, event, { json: true });
	const { 'content-type': _type, 'content-security-policy': _csp, ...headers } = response.headers;
	setResponseHeaders(event, headers);
	setResponseHeader(event, 'content-type', 'text/html;charset=utf-8');
	setResponseHeader(event, 'x-powered-by', 'Nuxt');
	setResponseStatus(event, response.status, response.statusText);
	const message = error.unhandled ? error.message || 'Server Error' : error.message || 'Server Error';
	const title = `${response.status} - ${escapeHtml(message)} | Nuxt`;
	return send(event, `<!DOCTYPE html><html><head><meta charset="utf-8"><title>${title}</title></head><body><h1>${response.status}</h1><p>${escapeHtml(message)}</p></body></html>`);
}

function escapeHtml(text: string): string {
	return text.replace(/[&<>"']/g, (char) => `&#${char.charCodeAt(0)};`);
}
