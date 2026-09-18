/**
 * Navigation programmée et middlewares de route : portage de nuxt/dist/app/composables/router.js
 * (Nuxt 4.5.2). Côté serveur, `navigateTo` prépare la réponse de redirection (302 et page
 * « meta refresh ») que le rendu renverra à la place de la page.
 */
import { hasProtocol, isScriptProtocol, joinURL, parseQuery, parseURL, withQuery, encodePath, decodePath } from 'ufo';
import type { RouteLocationNormalized, RouteLocationRaw } from 'vue-router';
import { sanitizeStatusCode } from '../../h3/error.ts';
import { createError, showError } from './data.ts';
import { isServer, runWithContext, useNuxtApp, useRouter, useRuntimeConfig } from './nuxt.ts';

export type RouteMiddleware = (to: RouteLocationNormalized, from: RouteLocationNormalized) => unknown;

export interface NavigateToOptions {
	replace?: boolean;
	redirectCode?: number;
	external?: boolean;
	open?: { target?: string; windowFeatures?: Record<string, unknown> };
}

export function defineNuxtRouteMiddleware(middleware: RouteMiddleware): RouteMiddleware {
	return middleware;
}

export function addRouteMiddleware(name: string | RouteMiddleware, middleware?: RouteMiddleware, options: { global?: boolean } = {}): void {
	const nuxtApp = useNuxtApp();
	const global = options.global || typeof name !== 'string';
	const mw = typeof name !== 'string' ? name : middleware;
	if (!mw) {
		console.warn(`[NUXT_E2006] No route middleware passed to \`addRouteMiddleware\`.`, name);
		return;
	}
	if (global) nuxtApp._middleware.global.push(mw);
	else nuxtApp._middleware.named[name as string] = mw;
}

export function isProcessingMiddleware(): boolean {
	try {
		if (useNuxtApp()._processingMiddleware) return true;
	} catch {
		return false;
	}
	return false;
}

const HTML_ATTR_UNSAFE_RE = /[&"'<>]/g;
const HTML_ATTR_ENCODE_MAP: Record<string, string> = { '&': '&amp;', '"': '&quot;', "'": '&#x27;', '<': '&lt;', '>': '&gt;' };

function encodeForHtmlAttr(value: string): string {
	return value.replace(HTML_ATTR_UNSAFE_RE, (c) => HTML_ATTR_ENCODE_MAP[c]);
}

export function navigateTo(to?: RouteLocationRaw | null, options?: NavigateToOptions): any {
	to ||= '/';
	const toPath = typeof to === 'string' ? to : 'path' in to ? resolveRouteObject(to as { path?: string; query?: Record<string, any>; hash?: string }) : useRouter().resolve(to).href;
	if (!isServer && options?.open) {
		const { protocol } = new URL(toPath, window.location.href);
		if (protocol && isScriptProtocol(protocol)) throw new Error(`[NUXT_E2002] Cannot navigate to a URL with '${protocol}' protocol.`);
		const { target = '_blank', windowFeatures = {} } = options.open;
		const features: string[] = [];
		for (const [feature, value] of Object.entries(windowFeatures)) if (value !== undefined) features.push(`${feature.toLowerCase()}=${value}`);
		open(toPath, target, features.join(', '));
		return Promise.resolve();
	}
	const isExternalHost = hasProtocol(toPath, { acceptRelative: true });
	const isExternal = options?.external || isExternalHost;
	if (isExternal) {
		if (!options?.external) throw new Error("[NUXT_E2001] Navigating to an external URL is not allowed by default. Use `navigateTo(url, { external: true })`.");
		const { protocol } = new URL(toPath, isServer ? 'http://localhost' : window.location.href);
		if (protocol && isScriptProtocol(protocol)) throw new Error(`[NUXT_E2002] Cannot navigate to a URL with '${protocol}' protocol.`);
	}
	const inMiddleware = isProcessingMiddleware();
	if (!isServer && !isExternal && inMiddleware) {
		if (options?.replace) {
			if (typeof to === 'string') {
				const { pathname, search, hash } = parseURL(to);
				return { path: pathname, ...(search && { query: parseQuery(search) }), ...(hash && { hash }), replace: true };
			}
			return { ...(to as object), replace: true };
		}
		return to;
	}
	const router = useRouter();
	const nuxtApp = useNuxtApp();
	if (isServer) {
		if (nuxtApp.ssrContext) {
			const fullPath = typeof to === 'string' || isExternal ? toPath : router.resolve(to).fullPath || '/';
			const location = isExternal ? toPath : joinURL(useRuntimeConfig().app.baseURL, fullPath);
			const redirect = async function (response?: unknown) {
				await nuxtApp.callHook('app:redirected');
				const encodedHeader = encodeURL(location, isExternalHost);
				const encodedLoc = encodeForHtmlAttr(encodedHeader);
				nuxtApp.ssrContext!['~renderResponse'] = {
					statusCode: sanitizeStatusCode(options?.redirectCode || 302, 302),
					body: `<!DOCTYPE html><html><head><meta http-equiv="refresh" content="0; url=${encodedLoc}"></head></html>`,
					headers: { location: encodedHeader },
				};
				return response;
			};
			if (!isExternal && inMiddleware) {
				router.afterEach((final) => (final.fullPath === fullPath ? redirect(false) : undefined));
				return to;
			}
			return redirect(!inMiddleware ? undefined : false);
		}
	}
	if (isExternal) {
		if (options?.replace) location.replace(toPath);
		else location.href = toPath;
		if (inMiddleware) {
			if (!nuxtApp.isHydrating) return false;
			return new Promise(() => {});
		}
		return Promise.resolve();
	}
	const encodedTo = typeof to === 'string' ? encodeRoutePath(to) : to;
	return options?.replace ? router.replace(encodedTo) : router.push(encodedTo);
}

export function abortNavigation(err?: string | Partial<Error> & Record<string, unknown>): false {
	if (!isProcessingMiddleware()) throw new Error('[NUXT_E2003] abortNavigation() is only usable inside a route middleware handler.');
	if (!err) return false;
	const error = createError(err);
	if (error.fatal) runWithContext(useNuxtApp(), () => showError(error));
	throw error;
}

function resolveRouteObject(to: { path?: string; query?: Record<string, any>; hash?: string }): string {
	return withQuery(to.path || '', to.query || {}) + (to.hash || '');
}

function encodeURL(location: string, isExternalHost = false): string {
	const url = new URL(location, 'http://localhost');
	if (!isExternalHost) return url.pathname.replace(/^\/{2,}/, '/') + url.search + url.hash;
	if (location.startsWith('//')) return url.toString().replace(url.protocol, '');
	return url.toString();
}

export function encodeRoutePath(url: string): string {
	const parsed = parseURL(url);
	return encodePath(decodePath(parsed.pathname)) + parsed.search + parsed.hash;
}
