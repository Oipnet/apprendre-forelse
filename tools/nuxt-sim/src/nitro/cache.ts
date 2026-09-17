/**
 * Cache de Nitro : `defineCachedFunction` et `defineCachedEventHandler`, portés de
 * nitropack/dist/runtime/internal/cache.mjs (Nitro 2.13) en gardant sa logique : `swr` par défaut
 * (une entrée expirée est servie, puis rafraîchie en arrière-plan), erreurs jamais gardées, ETag et
 * Last-Modified calculés sur la réponse. Seule différence : l'heure vient de l'horloge du simulateur,
 * que les tests peuvent avancer.
 */
import type { Storage } from 'unstorage';
import { H3Event, type HeaderValue } from '../h3/event.ts';
import { defineEventHandler, fetchWithEvent, handleCacheHeaders, type EventHandler } from '../h3/utils.ts';
import { hash } from './hash.ts';

export interface CacheOptions<T = unknown> {
	name?: string;
	base?: string;
	group?: string;
	swr?: boolean;
	maxAge?: number;
	staleMaxAge?: number;
	integrity?: string;
	validate?: (entry: CacheEntry<T>) => boolean;
	getKey?: (...args: any[]) => string | Promise<string>;
	transform?: (entry: CacheEntry<T>, ...args: any[]) => any;
	shouldBypassCache?: (...args: any[]) => boolean | Promise<boolean>;
	shouldInvalidateCache?: (...args: any[]) => boolean | Promise<boolean>;
	varies?: string[];
	headersOnly?: boolean;
}

interface CacheEntry<T = unknown> {
	value?: T;
	expires?: number;
	mtime?: number;
	integrity?: string;
}

export interface CacheContext {
	storage: Storage;
	now: () => number;
	/** `$fetch` et `fetch` locaux de Nitro, pour l'événement recréé par le cache. */
	$fetch: (request: string, init?: any) => Promise<any>;
	localFetch: (request: string, init?: any) => Promise<Response>;
}

function defaultCacheOptions(): CacheOptions {
	return { name: '_', base: '/cache', swr: true, maxAge: 1 };
}

function isEvent(input: unknown): input is H3Event {
	return !!input && typeof input === 'object' && '__is_event__' in input;
}

export function createCache(context: CacheContext) {
	const { storage, now } = context;

	function defineCachedFunction<T, A extends unknown[]>(fn: (...args: A) => T | Promise<T>, opts: CacheOptions<T> = {}): (...args: A) => Promise<T> {
		opts = { ...defaultCacheOptions(), ...opts } as CacheOptions<T>;
		const pending: Record<string, Promise<T> | undefined> = {};
		const group = opts.group || 'nitro/functions';
		const name = opts.name || fn.name || '_';
		const integrity = opts.integrity || hash([fn, opts]);
		const validate = opts.validate || ((entry: CacheEntry<T>) => entry.value !== undefined);

		async function get(key: string, resolver: () => T | Promise<T>, shouldInvalidateCache: boolean | undefined, event?: H3Event): Promise<CacheEntry<T>> {
			const cacheKey = [opts.base, group, name, `${key}.json`].filter(Boolean).join(':').replace(/:\/$/, ':index');
			let entry: CacheEntry<T> = (await storage.getItem<CacheEntry<T>>(cacheKey).catch((error) => {
				console.error('[cache] Cache read error.', error);
			})) || {};
			if (typeof entry !== 'object') {
				entry = {};
				console.error('[cache]', new Error('Malformed data read from cache.'));
			}
			const ttl = (opts.maxAge ?? 0) * 1e3;
			if (ttl) {
				entry.expires = now() + ttl;
			}
			const expired = shouldInvalidateCache || entry.integrity !== integrity || (ttl && now() - (entry.mtime || 0) > ttl) || validate(entry) === false;
			const _resolve = async () => {
				const isPending = pending[key];
				if (!isPending) {
					if (entry.value !== undefined && (opts.staleMaxAge || 0) >= 0 && opts.swr === false) {
						entry.value = undefined;
						entry.integrity = undefined;
						entry.mtime = undefined;
						entry.expires = undefined;
					}
					pending[key] = Promise.resolve(resolver());
				}
				try {
					entry.value = await pending[key];
				} catch (error) {
					if (!isPending) {
						delete pending[key];
					}
					throw error;
				}
				if (!isPending) {
					entry.mtime = now();
					entry.integrity = integrity;
					delete pending[key];
					if (validate(entry) !== false) {
						let setOpts: { ttl: number } | undefined;
						if (opts.maxAge && !opts.swr) {
							setOpts = { ttl: opts.maxAge };
						}
						const promise = storage.setItem(cacheKey, entry as never, setOpts).catch((error) => {
							console.error('[cache] Cache write error.', error);
						});
						if (event?.waitUntil) {
							event.waitUntil(promise);
						}
					}
				}
			};
			const _resolvePromise = expired ? _resolve() : Promise.resolve();
			if (entry.value === undefined) {
				await _resolvePromise;
			} else if (expired && event && event.waitUntil) {
				event.waitUntil(_resolvePromise);
			}
			if (opts.swr && validate(entry) !== false) {
				_resolvePromise.catch((error) => {
					console.error('[cache] SWR handler error.', error);
				});
				return entry;
			}
			return _resolvePromise.then(() => entry);
		}

		return async (...args: A) => {
			const shouldBypassCache = await opts.shouldBypassCache?.(...args);
			if (shouldBypassCache) {
				return fn(...args);
			}
			const key = await (opts.getKey || getKey)(...args);
			const shouldInvalidateCache = await opts.shouldInvalidateCache?.(...args);
			const entry = await get(key, () => fn(...args), shouldInvalidateCache, args[0] && isEvent(args[0]) ? args[0] : undefined);
			let value = entry.value as T;
			if (opts.transform) {
				value = (await opts.transform(entry, ...args)) || value;
			}
			return value;
		};
	}

	function defineCachedEventHandler<T>(handler: EventHandler<T>, opts: CacheOptions = defaultCacheOptions()): EventHandler {
		const variableHeaderNames = (opts.varies || []).filter(Boolean).map((h) => h.toLowerCase()).sort();
		const _opts: CacheOptions<any> = {
			...opts,
			getKey: async (event: H3Event) => {
				const customKey = await opts.getKey?.(event);
				if (customKey) {
					return escapeKey(customKey);
				}
				const _path = event.node.req.originalUrl || event.node.req.url || event.path;
				let _pathname: string;
				try {
					// parseURL(_path).pathname de ufo, pour un chemin relatif : ce qui précède « ? » ou « # ».
					_pathname = escapeKey(decodeURI(_path.split(/[?#]/)[0])).slice(0, 16) || 'index';
				} catch {
					_pathname = '-';
				}
				const _hashedPath = `${_pathname}.${hash(_path)}`;
				const _headers = variableHeaderNames.map((header) => [header, event.node.req.headers[header]]).map(([name, value]) => `${escapeKey(name)}.${hash(value)}`);
				return [_hashedPath, ..._headers].join(':');
			},
			validate: (entry: CacheEntry<any>) => {
				if (!entry.value) return false;
				if (entry.value.code >= 400) return false;
				if (entry.value.body === undefined) return false;
				if (entry.value.headers.etag === 'undefined' || entry.value.headers['last-modified'] === 'undefined') return false;
				return true;
			},
			group: opts.group || 'nitro/handlers',
			integrity: opts.integrity || hash([handler, opts]),
		};

		const _cachedHandler = defineCachedFunction(async (incomingEvent: H3Event) => {
			const variableHeaders: Record<string, string> = {};
			for (const header of variableHeaderNames) {
				const value = incomingEvent.node.req.headers[header];
				if (value !== undefined) {
					variableHeaders[header] = value;
				}
			}
			const reqProxy = cloneWithProxy(incomingEvent.node.req, { headers: variableHeaders });
			const resHeaders: Record<string, HeaderValue> = {};
			let _resSendBody: unknown;
			const resProxy = cloneWithProxy(incomingEvent.node.res, {
				statusCode: 200,
				writableEnded: false,
				writableFinished: false,
				headersSent: false,
				closed: false,
				getHeader(name: string) {
					return resHeaders[name];
				},
				setHeader(name: string, value: HeaderValue) {
					resHeaders[name] = value;
					return this;
				},
				getHeaderNames() {
					return Object.keys(resHeaders);
				},
				hasHeader(name: string) {
					return name in resHeaders;
				},
				removeHeader(name: string) {
					delete resHeaders[name];
				},
				getHeaders() {
					return resHeaders;
				},
				end(chunk: unknown) {
					if (typeof chunk === 'string') {
						_resSendBody = chunk;
					}
					return this;
				},
				writeHead(this: { statusCode: number; setHeader: (name: string, value: HeaderValue) => void }, statusCode: number, headers?: Record<string, HeaderValue>) {
					this.statusCode = statusCode;
					if (headers) {
						for (const header in headers) {
							if (headers[header] !== undefined) this.setHeader(header, headers[header]);
						}
					}
					return this;
				},
			});
			const event = new H3Event(reqProxy, resProxy);
			event.fetch = (url, fetchOptions) => fetchWithEvent(event, url, fetchOptions as Record<string, any>, { fetch: context.localFetch });
			event.$fetch = (url, fetchOptions) => fetchWithEvent(event, url, fetchOptions, { fetch: context.$fetch });
			event.waitUntil = incomingEvent.waitUntil;
			event.context = incomingEvent.context;
			event.context.cache = { options: _opts };
			const body = (await handler(event)) || _resSendBody;
			const headers = event.node.res.getHeaders() as Record<string, HeaderValue>;
			headers.etag = String(headers.Etag || headers.etag || `W/"${hash(body)}"`);
			headers['last-modified'] = String(headers['Last-Modified'] || headers['last-modified'] || new Date(now()).toUTCString());
			const cacheControl: string[] = [];
			if (opts.swr) {
				if (opts.maxAge) {
					cacheControl.push(`s-maxage=${opts.maxAge}`);
				}
				if (opts.staleMaxAge) {
					cacheControl.push(`stale-while-revalidate=${opts.staleMaxAge}`);
				} else {
					cacheControl.push('stale-while-revalidate');
				}
			} else if (opts.maxAge) {
				cacheControl.push(`max-age=${opts.maxAge}`);
			}
			if (cacheControl.length > 0) {
				headers['cache-control'] = cacheControl.join(', ');
			}
			return { code: event.node.res.statusCode, headers, body };
		}, _opts);

		return defineEventHandler(async (event: H3Event) => {
			if (opts.headersOnly) {
				if (handleCacheHeaders(event, { maxAge: opts.maxAge })) {
					return;
				}
				return handler(event);
			}
			const response = await _cachedHandler(event);
			if (event.node.res.writableEnded) {
				return response.body;
			}
			if (handleCacheHeaders(event, { modifiedTime: new Date(response.headers['last-modified'] as string), etag: response.headers.etag as string, maxAge: opts.maxAge })) {
				return;
			}
			event.node.res.statusCode = response.code;
			for (const name in response.headers) {
				const value = response.headers[name];
				if (value !== undefined) {
					event.node.res.setHeader(name, value);
				}
			}
			return response.body;
		});
	}

	return {
		defineCachedFunction,
		cachedFunction: defineCachedFunction,
		defineCachedEventHandler,
		cachedEventHandler: defineCachedEventHandler,
	};
}

function getKey(...args: unknown[]): string {
	return args.length > 0 ? hash(args) : '';
}

function escapeKey(key: unknown): string {
	return String(key).replace(/\W/g, '');
}

function cloneWithProxy<T extends object>(obj: T, overrides: Record<string | symbol, any>): T {
	return new Proxy(obj, {
		get(target, property, receiver) {
			if (property in overrides) {
				return overrides[property];
			}
			return Reflect.get(target, property, receiver);
		},
		set(target, property, value, receiver) {
			if (property in overrides) {
				overrides[property] = value;
				return true;
			}
			return Reflect.set(target, property, value, receiver);
		},
	});
}
