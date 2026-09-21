/**
 * `useCookie` : portage de nuxt/dist/app/composables/cookie.js (Nuxt 4.5.2). Côté serveur, la valeur est
 * lue dans l'en-tête `cookie` de la requête et écrite en `set-cookie` à la fin du rendu ; dans le
 * navigateur, dans `document.cookie`. Sans `cookieStore` (option expérimentale de Nuxt, désactivée).
 */
import { parse, serialize } from 'cookie-es-nuxt';
import { klona } from 'klona';
import { isEqual } from 'ohash';
import { customRef, getCurrentScope, nextTick, onScopeDispose, ref, watch, type Ref } from 'vue';
import { deleteCookie, getCookie, getRequestHeader, setCookie } from '../../h3/utils.ts';
import { useRequestEvent } from './data.ts';
import { devWarning, isServer, useNuxtApp } from './nuxt.ts';

export interface CookieOptions<T = any> {
	decode?: (value: string) => T;
	encode?: (value: T) => string;
	default?: () => T | Ref<T>;
	watch?: boolean | 'shallow';
	readonly?: boolean;
	refresh?: boolean;
	filter?: (key: string) => boolean;
	maxAge?: number;
	expires?: Date;
	path?: string;
	domain?: string;
	secure?: boolean;
	httpOnly?: boolean;
	sameSite?: boolean | 'lax' | 'strict' | 'none';
	partitioned?: boolean;
	priority?: 'low' | 'medium' | 'high';
}

function parseCookieValue(value: string): unknown {
	if (value === 'undefined') return undefined;
	try {
		const parsed = JSON.parse(value);
		if (typeof parsed === 'number' && String(parsed) !== value) return value;
		return parsed;
	} catch {
		return value;
	}
}

const CookieDefaults = {
	path: '/',
	watch: true,
	decode: (val: string) => (val ? parseCookieValue(decodeURIComponent(val)) : val),
	encode: (val: unknown) => {
		if (typeof val !== 'string' || val === 'undefined') return encodeURIComponent(JSON.stringify(val));
		try {
			if (typeof JSON.parse(val) !== 'string') return encodeURIComponent(JSON.stringify(val));
		} catch {
			// Une chaîne qui n'est pas du JSON : encodée telle quelle.
		}
		return encodeURIComponent(val);
	},
	refresh: false,
};

export function useCookie<T = string | null | undefined>(name: string, _opts?: CookieOptions<T>): Ref<T> {
	const opts: Record<string, any> = { ...CookieDefaults, ..._opts };
	opts.filter ??= (key: string) => key === name;
	const cookies = readRawCookies(opts) || {};
	let delay: number | undefined;
	if (opts.maxAge !== undefined) delay = opts.maxAge * 1e3;
	else if (opts.expires) delay = opts.expires.getTime() - Date.now();
	const hasExpired = delay !== undefined && delay <= 0;
	const shouldSetInitialClientCookie = !isServer && (hasExpired || cookies[name] === undefined || cookies[name] === null);
	const cookieValue = klona(hasExpired ? undefined : cookies[name] ?? opts.default?.());
	const cookie = !isServer && delay && !hasExpired ? cookieRef(cookieValue, delay, opts.watch && opts.watch !== 'shallow') : isServer ? cookieServerRef(name, cookieValue) : ref(cookieValue);
	if (hasExpired) devWarning(useNuxtApp(), 'NUXT_E7005', `The cookie "${name}" has already expired.`);
	if (!isServer) {
		let channel: BroadcastChannel | null = null;
		try {
			if (typeof BroadcastChannel !== 'undefined') channel = new BroadcastChannel(`nuxt:cookies:${name}`);
		} catch {
			// Canal indisponible : la synchronisation entre onglets se fait sans lui.
		}
		const callback = (force = false) => {
			if (!force) {
				if (opts.readonly || isEqual(cookie.value, cookies[name])) return;
			}
			writeClientCookie(name, cookie.value === null || cookie.value === undefined ? undefined : opts.encode(cookie.value), opts);
			cookies[name] = klona(cookie.value);
			channel?.postMessage({ value: opts.encode(cookie.value) });
		};
		const handleChange = (data: { refresh?: boolean; value?: string | null }) => {
			const value = data.refresh ? readRawCookies(opts)?.[name] : opts.decode(data.value);
			watchPaused = true;
			cookie.value = value;
			cookies[name] = klona(value);
			nextTick(() => {
				watchPaused = false;
			});
		};
		let watchPaused = false;
		const hasScope = !!getCurrentScope();
		if (hasScope) onScopeDispose(() => {
			watchPaused = true;
			callback();
			channel?.close();
		});
		if (channel) channel.onmessage = ({ data }) => handleChange(data);
		if (opts.watch) watch(cookie, () => {
			if (watchPaused) return;
			callback(opts.refresh);
		}, { deep: opts.watch !== 'shallow' });
		if (shouldSetInitialClientCookie) callback(shouldSetInitialClientCookie);
	} else {
		const nuxtApp = useNuxtApp();
		const writeFinalCookieValue = () => {
			const valueIsSame = isEqual(cookie.value, cookies[name]);
			if (opts.readonly || (valueIsSame && !opts.refresh)) return;
			nuxtApp._cookiesChanged ||= {};
			if (valueIsSame && opts.refresh && !nuxtApp._cookiesChanged[name]) return;
			nuxtApp._cookies ||= {};
			if (name in nuxtApp._cookies) {
				if (isEqual(cookie.value, nuxtApp._cookies[name])) return;
				devWarning(nuxtApp, 'NUXT_E7006', `Cookie "${name}" is being set with a different value during SSR ("${opts.encode(nuxtApp._cookies[name])}" → "${opts.encode(cookie.value)}").`);
			}
			nuxtApp._cookies[name] = cookie.value;
			const encoded = cookie.value === null || cookie.value === undefined ? undefined : opts.encode(cookie.value);
			writeServerCookie(useRequestEvent(nuxtApp), name, encoded, opts);
		};
		const unhook = nuxtApp.hooks.hookOnce('app:rendered', writeFinalCookieValue);
		nuxtApp.hooks.hookOnce('app:error', () => {
			unhook();
			return writeFinalCookieValue();
		});
	}
	return cookie as Ref<T>;
}

export function refreshCookie(name: string): void {
	if (isServer || typeof BroadcastChannel === 'undefined') return;
	try {
		const channel = new BroadcastChannel(`nuxt:cookies:${name}`);
		channel.postMessage({ refresh: true });
		channel.close();
	} catch {
		// Canal indisponible.
	}
}

function readRawCookies(opts: Record<string, any> = {}): Record<string, any> | undefined {
	if (isServer) return parse(getRequestHeader(useRequestEvent(), 'cookie') || '', opts);
	return parse(document.cookie, opts);
}

const identityEncode = (val: string) => val;

function toSerializeOptions(opts: Record<string, any>): Record<string, any> {
	const { encode: _encode, decode: _decode, ...rest } = opts;
	return { ...rest, encode: identityEncode };
}

function serializeCookie(name: string, value: string | undefined, opts: Record<string, any> = {}): string {
	const serializeOpts = toSerializeOptions(opts);
	if (value === undefined) return serialize(name, '', { ...serializeOpts, maxAge: -1 });
	return serialize(name, value, serializeOpts);
}

function writeClientCookie(name: string, value: string | undefined, opts: Record<string, any> = {}): void {
	if (!isServer) document.cookie = serializeCookie(name, value, opts);
}

function writeServerCookie(event: any, name: string, value: string | undefined, opts: Record<string, any> = {}): void {
	if (event) {
		const serializeOpts = toSerializeOptions(opts);
		if (value !== undefined) return setCookie(event, name, value, serializeOpts);
		if (getCookie(event, name) !== undefined) return deleteCookie(event, name, serializeOpts);
	}
}

const MAX_TIMEOUT_DELAY = 2147483647;

function cookieRef<T>(value: T, delay: number, shouldWatch: boolean) {
	let timeout: ReturnType<typeof setTimeout>;
	let unsubscribe: (() => void) | undefined;
	let elapsed = 0;
	const internalRef = shouldWatch ? ref(value) : { value };
	if (getCurrentScope()) onScopeDispose(() => {
		unsubscribe?.();
		clearTimeout(timeout);
	});
	return customRef((track, trigger) => {
		if (shouldWatch) unsubscribe = watch(internalRef as Ref<T>, trigger);
		function scheduleTimeout() {
			const timeRemaining = delay - elapsed;
			const timeoutLength = timeRemaining < MAX_TIMEOUT_DELAY ? timeRemaining : MAX_TIMEOUT_DELAY;
			timeout = setTimeout(() => {
				elapsed += timeoutLength;
				if (elapsed < delay) return scheduleTimeout();
				internalRef.value = undefined as T;
				trigger();
			}, timeoutLength);
		}
		function createExpirationTimeout() {
			elapsed = 0;
			clearTimeout(timeout);
			scheduleTimeout();
		}
		return {
			get() {
				track();
				return internalRef.value;
			},
			set(newValue: T) {
				createExpirationTimeout();
				internalRef.value = newValue;
				trigger();
			},
		};
	});
}

function cookieServerRef<T>(name: string, value: T) {
	const internalRef = ref(value);
	const nuxtApp = useNuxtApp();
	return customRef((track, trigger) => ({
		get() {
			track();
			return internalRef.value as T;
		},
		set(newValue: T) {
			nuxtApp._cookiesChanged ||= {};
			nuxtApp._cookiesChanged[name] = true;
			internalRef.value = newValue as never;
			trigger();
		},
	}));
}
