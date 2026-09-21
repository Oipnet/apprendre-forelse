/**
 * Fonctions h3 1.x auto-importées dans `server/`. Chacune reprend le code de h3 (dist/index.mjs)
 * en remplaçant Node par les imitations de ./event.ts. N'en ajouter une qu'avec un cas de conformité.
 */
import { parse as parseCookieHeader, parseSetCookie, serialize as serializeCookie, type CookieSerializeOptions } from 'cookie-es';
import { destr } from '../unjs/destr.ts';
import { decode, parseQuery, searchOf, type QueryValue } from '../unjs/ufo.ts';
import { createError, sanitizeStatusCode, sanitizeStatusMessage } from './error.ts';
import type { H3Event, HeaderValue } from './event.ts';

export type EventHandler<T = unknown> = ((event: H3Event) => T | Promise<T>) & { __is_handler__?: true };

export const MIMES = { html: 'text/html', json: 'application/json' } as const;

export function defineEventHandler<T>(handler: EventHandler<T> | { handler: EventHandler<T> }): EventHandler<T> {
	if (typeof handler === 'function') {
		handler.__is_handler__ = true;
		return handler;
	}
	// Forme objet ({ onRequest, handler, onBeforeResponse }) : les crochets viendront avec leur cas de conformité.
	const wrapped: EventHandler<T> = (event) => handler.handler(event);
	wrapped.__is_handler__ = true;
	return wrapped;
}

export const eventHandler = defineEventHandler;

export function isEventHandler(input: unknown): input is EventHandler {
	return typeof input === 'function' && '__is_handler__' in input;
}

// --- Requête ---

export function getQuery(event: H3Event): Record<string, QueryValue> {
	return parseQuery(searchOf(event.path || ''));
}

export function getRouterParams(event: H3Event, opts: { decode?: boolean } = {}): Record<string, string> {
	let params: Record<string, string> = event.context.params || {};
	if (opts.decode) {
		params = { ...params };
		for (const key in params) {
			params[key] = decode(params[key]);
		}
	}
	return params;
}

export function getRouterParam(event: H3Event, name: string, opts: { decode?: boolean } = {}): string | undefined {
	return getRouterParams(event, opts)[name];
}

// --- Validation (h3 : validateData) ---

type Validator<T> = (data: unknown) => T | true | false | void | Promise<T | true | false | void>;

async function validateData<T>(data: unknown, validate: Validator<T>): Promise<T> {
	try {
		const result = await validate(data);
		if (result === false) {
			throw createValidationError();
		}
		if (result === true) {
			return data as T;
		}
		return (result ?? data) as T;
	} catch (error) {
		throw createValidationError(error as Error | undefined);
	}
}

function createValidationError(validateError?: Error): never {
	throw createError({
		status: 400,
		statusMessage: 'Validation Error',
		message: validateError?.message || 'Validation Error',
		data: validateError,
	});
}

export function getValidatedQuery<T>(event: H3Event, validate: Validator<T>): Promise<T> {
	return validateData(getQuery(event), validate);
}

export function getValidatedRouterParams<T>(event: H3Event, validate: Validator<T>, opts: { decode?: boolean } = {}): Promise<T> {
	return validateData(getRouterParams(event, opts), validate);
}

export async function readValidatedBody<T>(event: H3Event, validate: Validator<T>): Promise<T> {
	const body = await readBody(event, { strict: true });
	return validateData(body, validate);
}

export function getMethod(event: H3Event, defaultMethod = 'GET'): string {
	return (event.node.req.method || defaultMethod).toUpperCase();
}

export function isMethod(event: H3Event, expected: string | string[], allowHead?: boolean): boolean {
	if (allowHead && event.method === 'HEAD') {
		return true;
	}
	return typeof expected === 'string' ? event.method === expected : expected.includes(event.method);
}

export function assertMethod(event: H3Event, expected: string | string[], allowHead?: boolean): void {
	if (!isMethod(event, expected, allowHead)) {
		throw createError({ statusCode: 405, statusMessage: 'HTTP method is not allowed.' });
	}
}

export function getRequestHeaders(event: H3Event): Record<string, string> {
	return { ...event.node.req.headers };
}

export const getHeaders = getRequestHeaders;

export function getRequestHeader(event: H3Event, name: string): string | undefined {
	return getRequestHeaders(event)[name.toLowerCase()];
}

export const getHeader = getRequestHeader;

export function getRequestHost(event: H3Event, opts: { xForwardedHost?: boolean } = {}): string {
	if (opts.xForwardedHost) {
		const forwarded = (event.node.req.headers['x-forwarded-host'] || '').split(',').shift()?.trim();
		if (forwarded) {
			return forwarded;
		}
	}
	return event.node.req.headers.host || 'localhost';
}

export function getRequestProtocol(event: H3Event, opts: { xForwardedProto?: boolean } = {}): 'http' | 'https' {
	return opts.xForwardedProto !== false && event.node.req.headers['x-forwarded-proto'] === 'https' ? 'https' : 'http';
}

export function getRequestURL(event: H3Event, opts: { xForwardedHost?: boolean; xForwardedProto?: boolean } = {}): URL {
	const host = getRequestHost(event, opts);
	const protocol = getRequestProtocol(event, opts);
	const path = (event.node.req.originalUrl || event.path).replace(/^[/\\]+/g, '/');
	return new URL(path, `${protocol}://${host}`);
}

/** En-têtes que h3 ne recopie pas vers une requête relayée (getProxyRequestHeaders). */
const IGNORED_PROXY_HEADERS = new Set(['transfer-encoding', 'accept-encoding', 'connection', 'keep-alive', 'upgrade', 'expect', 'host', 'accept']);

export function getProxyRequestHeaders(event: H3Event, opts?: { host?: boolean }): Record<string, string> {
	const headers: Record<string, string> = Object.create(null);
	const reqHeaders = getRequestHeaders(event);
	for (const name in reqHeaders) {
		if (!IGNORED_PROXY_HEADERS.has(name) || (name === 'host' && opts?.host)) {
			headers[name] = reqHeaders[name];
		}
	}
	return headers;
}

/** `fetchWithEvent` : la requête part avec le contexte et les en-têtes de l'événement. */
export function fetchWithEvent<T>(event: H3Event, req: string, init: Record<string, any> | undefined, options: { fetch: (req: string, init: Record<string, any>) => T }): T {
	return options.fetch(req, {
		...init,
		context: init?.context || event.context,
		headers: {
			...getProxyRequestHeaders(event, { host: typeof req === 'string' && req.startsWith('/') }),
			...init?.headers,
		},
	});
}

const PAYLOAD_METHODS = ['PATCH', 'POST', 'PUT', 'DELETE'];

export async function readRawBody(event: H3Event, encoding: 'utf8' | false = 'utf8'): Promise<string | Uint8Array | undefined> {
	assertMethod(event, PAYLOAD_METHODS);
	const raw = event.node.req.rawBody;
	if (!raw) {
		return undefined;
	}
	return encoding ? new TextDecoder().decode(raw) : raw;
}

const PARSED_BODY = Symbol.for('h3ParsedBody');

export async function readBody<T = any>(event: H3Event, options: { strict?: boolean } = {}): Promise<T> {
	const request = event.node.req as unknown as Record<symbol, unknown>;
	if (PARSED_BODY in request) {
		return request[PARSED_BODY] as T;
	}
	const contentType = event.node.req.headers['content-type'] || '';
	const body = (await readRawBody(event)) as string | undefined;
	let parsed: unknown;
	if (contentType === 'application/json') {
		parsed = parseJsonBody(body, options.strict ?? true);
	} else if (contentType.startsWith('application/x-www-form-urlencoded')) {
		parsed = parseUrlEncodedBody(body);
	} else if (contentType.startsWith('text/')) {
		parsed = body;
	} else {
		parsed = parseJsonBody(body, options.strict ?? false);
	}
	request[PARSED_BODY] = parsed;
	return parsed as T;
}

function parseJsonBody(body = '', strict: boolean): unknown {
	if (!body) {
		return undefined;
	}
	try {
		return destr(body, { strict });
	} catch {
		throw createError({ statusCode: 400, statusMessage: 'Bad Request', message: 'Invalid JSON body' });
	}
}

function parseUrlEncodedBody(body = ''): Record<string, string | string[]> {
	const parsed: Record<string, string | string[]> = Object.create(null);
	for (const [key, value] of new URLSearchParams(body).entries()) {
		const current = parsed[key];
		if (key in parsed) {
			parsed[key] = Array.isArray(current) ? [...current, value] : [current, value];
		} else {
			parsed[key] = value;
		}
	}
	return parsed;
}

// --- Cookies (h3 1.x, cookie-es 1.2) ---

export function parseCookies(event: H3Event): Record<string, string> {
	return parseCookieHeader(event.node.req.headers.cookie || '');
}

export function getCookie(event: H3Event, name: string): string | undefined {
	return parseCookies(event)[name];
}

export function setCookie(event: H3Event, name: string, value: string, serializeOptions: CookieSerializeOptions = {}): void {
	if (!serializeOptions.path) {
		serializeOptions = { path: '/', ...serializeOptions };
	}
	const newCookie = serializeCookie(name, value, serializeOptions);
	const currentCookies = splitCookiesString(event.node.res.getHeader('set-cookie') as string | string[] | undefined);
	if (currentCookies.length === 0) {
		event.node.res.setHeader('set-cookie', newCookie);
		return;
	}
	const newCookieKey = getDistinctCookieKey(name, serializeOptions);
	const kept: string[] = [];
	for (const cookie of currentCookies) {
		const parsed = parseSetCookie(cookie);
		if (getDistinctCookieKey(parsed.name, parsed) === newCookieKey) continue;
		kept.push(cookie);
	}
	event.node.res.setHeader('set-cookie', [...kept, newCookie]);
}

export function deleteCookie(event: H3Event, name: string, serializeOptions?: CookieSerializeOptions): void {
	setCookie(event, name, '', { ...serializeOptions, maxAge: 0 });
}

function getDistinctCookieKey(name: string, opts: { domain?: string; path?: string }): string {
	return [name, opts.domain || '', opts.path || '/'].join(';');
}

export function splitCookiesString(cookiesString: string | string[] | undefined): string[] {
	if (Array.isArray(cookiesString)) {
		return cookiesString.flatMap((c) => splitCookiesString(c));
	}
	if (typeof cookiesString !== 'string') {
		return [];
	}
	const cookiesStrings: string[] = [];
	let pos = 0;
	let start: number;
	let ch: string;
	let lastComma: number;
	let nextStart: number;
	let cookiesSeparatorFound: boolean;
	const skipWhitespace = () => {
		while (pos < cookiesString.length && /\s/.test(cookiesString.charAt(pos))) pos += 1;
		return pos < cookiesString.length;
	};
	const notSpecialChar = () => {
		ch = cookiesString.charAt(pos);
		return ch !== '=' && ch !== ';' && ch !== ',';
	};
	while (pos < cookiesString.length) {
		start = pos;
		cookiesSeparatorFound = false;
		while (skipWhitespace()) {
			ch = cookiesString.charAt(pos);
			if (ch === ',') {
				lastComma = pos;
				pos += 1;
				skipWhitespace();
				nextStart = pos;
				while (pos < cookiesString.length && notSpecialChar()) pos += 1;
				if (pos < cookiesString.length && cookiesString.charAt(pos) === '=') {
					cookiesSeparatorFound = true;
					pos = nextStart;
					cookiesStrings.push(cookiesString.slice(start, lastComma));
					start = pos;
				} else {
					pos = lastComma + 1;
				}
			} else {
				pos += 1;
			}
		}
		if (!cookiesSeparatorFound || pos >= cookiesString.length) {
			cookiesStrings.push(cookiesString.slice(start));
		}
	}
	return cookiesStrings;
}

// --- Réponse ---

/** En-têtes de cache et réponse 304 quand le client a déjà la bonne version. */
export function handleCacheHeaders(event: H3Event, opts: { cacheControls?: string[]; maxAge?: number; modifiedTime?: Date | string | number; etag?: string }): boolean {
	const cacheControls = ['public', ...(opts.cacheControls || [])];
	let cacheMatched = false;
	if (opts.maxAge !== undefined) {
		cacheControls.push(`max-age=${+opts.maxAge}`, `s-maxage=${+opts.maxAge}`);
	}
	if (opts.modifiedTime) {
		const modifiedTime = new Date(opts.modifiedTime);
		const ifModifiedSince = event.node.req.headers['if-modified-since'];
		event.node.res.setHeader('last-modified', modifiedTime.toUTCString());
		if (ifModifiedSince && new Date(ifModifiedSince) >= modifiedTime) {
			cacheMatched = true;
		}
	}
	if (opts.etag) {
		event.node.res.setHeader('etag', opts.etag);
		const ifNonMatch = event.node.req.headers['if-none-match'];
		if (ifNonMatch === opts.etag) {
			cacheMatched = true;
		}
	}
	event.node.res.setHeader('cache-control', cacheControls.join(', '));
	if (cacheMatched) {
		event.node.res.statusCode = 304;
		if (!event.handled) {
			event.node.res.end();
		}
		return true;
	}
	return false;
}

export function setResponseStatus(event: H3Event, code?: number, text?: string): void {
	if (code) {
		event.node.res.statusCode = sanitizeStatusCode(code, event.node.res.statusCode);
	}
	if (text) {
		event.node.res.statusMessage = sanitizeStatusMessage(text);
	}
}

export function getResponseStatus(event: H3Event): number {
	return event.node.res.statusCode;
}

export function getResponseStatusText(event: H3Event): string | undefined {
	return event.node.res.statusMessage;
}

export function getResponseHeaders(event: H3Event): Record<string, HeaderValue> {
	return event.node.res.getHeaders();
}

export function getResponseHeader(event: H3Event, name: string): HeaderValue | undefined {
	return event.node.res.getHeader(name);
}

export function setResponseHeaders(event: H3Event, headers: Record<string, HeaderValue>): void {
	for (const [name, value] of Object.entries(headers)) {
		event.node.res.setHeader(name, value);
	}
}

export const setHeaders = setResponseHeaders;

export function setResponseHeader(event: H3Event, name: string, value: HeaderValue): void {
	event.node.res.setHeader(name, value);
}

export const setHeader = setResponseHeader;

export function appendResponseHeader(event: H3Event, name: string, value: string): void {
	const current = event.node.res.getHeader(name);
	if (!current) {
		event.node.res.setHeader(name, value);
		return;
	}
	event.node.res.setHeader(name, [...(Array.isArray(current) ? current : [current.toString()]), value]);
}

export const appendHeader = appendResponseHeader;

export function removeResponseHeader(event: H3Event, name: string): void {
	event.node.res.removeHeader(name);
}

function defaultContentType(event: H3Event, type?: string): void {
	if (type && event.node.res.statusCode !== 304 && !event.node.res.getHeader('content-type')) {
		event.node.res.setHeader('content-type', type);
	}
}

export async function send(event: H3Event, data?: string | Uint8Array, type?: string): Promise<void> {
	defaultContentType(event, type);
	if (!event.handled) {
		event.node.res.end(data);
	}
}

export function sendNoContent(event: H3Event, code?: number): void {
	if (event.handled) {
		return;
	}
	if (!code && event.node.res.statusCode !== 200) {
		code = event.node.res.statusCode;
	}
	const status = sanitizeStatusCode(code, 204);
	if (status === 204) {
		event.node.res.removeHeader('content-length');
	}
	event.node.res.writeHead(status);
	event.node.res.end();
}

export function sendRedirect(event: H3Event, location: string, code = 302): Promise<void> {
	event.node.res.statusCode = sanitizeStatusCode(code, event.node.res.statusCode);
	event.node.res.setHeader('location', location);
	const encoded = location.replace(/"/g, '%22');
	return send(event, `<!DOCTYPE html><html><head><meta http-equiv="refresh" content="0; url=${encoded}"></head></html>`, MIMES.html);
}

/** Envoie la valeur rendue par un gestionnaire, comme `handleHandlerResponse` de h3. */
export async function handleHandlerResponse(event: H3Event, value: unknown, jsonSpace?: number): Promise<void> {
	if (value === null) {
		return sendNoContent(event);
	}
	if (value) {
		if (value instanceof Uint8Array) {
			return send(event, value);
		}
		if (value instanceof Error) {
			throw createError(value);
		}
	}
	switch (typeof value) {
		case 'string':
			return send(event, value, MIMES.html);
		case 'object':
		case 'boolean':
		case 'number':
			return send(event, JSON.stringify(value, undefined, jsonSpace), MIMES.json);
		case 'bigint':
			return send(event, value.toString(), MIMES.json);
	}
	throw createError({ statusCode: 500, statusMessage: `[h3] Cannot send ${typeof value} as response.` });
}
