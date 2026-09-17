/**
 * Récupération des données : `useAsyncData`, `useFetch`, `callOnce`, `$fetch`, et les erreurs de Nuxt.
 * Portage de nuxt/dist/app/composables/{asyncData,fetch,once,error,ssr}.js (Nuxt 4.5.2), réglages par
 * défaut de Nuxt 4 (#build/nuxt.config.mjs) : `deep: false`, `granularCachedData`, `purgeCachedData`,
 * sans `pendingWhenIdle` ni `alwaysRunFetchOnKeyChange`. La structure du code d'origine est gardée,
 * pour qu'un comportement surprenant (deux requêtes malgré une clé partagée) le soit ici aussi.
 */
import { fnv1a64Base36 } from 'fnv1a-64';
import { identify } from 'object-identity';
import { $fetch as ofetch, type $Fetch } from 'ofetch';
import {
	computed, getCurrentInstance, getCurrentScope, inject, isRef, isShallow, nextTick, onBeforeMount, onScopeDispose, onServerPrefetch, onUnmounted,
	queuePostFlushCb, reactive, ref, shallowRef, toRef, toValue, unref, watch,
} from 'vue';
import { createError as createH3Error, type H3Error } from '../../h3/error.ts';
import { devWarning, isServer, useNuxtApp, type NuxtApp } from './nuxt.ts';

const asyncDataDefaults = { deep: false };
const granularCachedData = true;
const purgeCachedData = true;

// --- $fetch (#build/fetch.mjs) -------------------------------------------------------------------

/**
 * Dans le navigateur : ofetch préfixé par `app.baseURL`. Côté serveur, le simulateur passe son propre
 * `$fetch` (celui de Nitro, qui répond sans réseau) dans le contexte de rendu et dans les auto-imports.
 */
function globalFetch(): $Fetch {
	const scope = globalThis as { $fetch?: $Fetch; __NUXT__?: { config?: { app?: { baseURL?: string } } } };
	if (!isServer) {
		scope.$fetch ||= ofetch.create({ baseURL: scope.__NUXT__?.config?.app?.baseURL ?? '/' });
		return scope.$fetch;
	}
	const nuxtApp = tryUseNuxtApp();
	return nuxtApp?.ssrContext?.$fetch ?? ofetch;
}

export const $fetch = Object.assign(
	((request: any, options?: any) => globalFetch()(request, options)) as $Fetch,
	{
		raw: ((request: any, options?: any) => globalFetch().raw(request, options)) as $Fetch['raw'],
		native: ((...args: Parameters<typeof fetch>) => globalFetch().native(...args)) as $Fetch['native'],
		create: ((defaults: any, globalOptions?: any) => globalFetch().create(defaults, globalOptions)) as $Fetch['create'],
	},
);

function tryUseNuxtApp(): NuxtApp | undefined {
	try {
		return useNuxtApp();
	} catch {
		return undefined;
	}
}

// --- ssr.js --------------------------------------------------------------------------------------

export function useRequestEvent(nuxtApp?: NuxtApp): any {
	if (!isServer) return undefined;
	nuxtApp ||= useNuxtApp();
	return nuxtApp.ssrContext?.event;
}

export function useRequestFetch(): $Fetch {
	if (!isServer) return $fetch;
	return useRequestEvent()?.$fetch || $fetch;
}

// --- error.js ------------------------------------------------------------------------------------

const NUXT_ERROR_SIGNATURE = '__nuxt_error';

export type NuxtError<DataT = unknown> = H3Error<DataT> & { __nuxt_error: true; status: number; statusText?: string };

export const isNuxtError = (error: unknown): error is NuxtError => !!error && typeof error === 'object' && NUXT_ERROR_SIGNATURE in error;

export function createError<DataT = unknown>(error: any): NuxtError<DataT> {
	if (typeof error !== 'string' && error.statusText) error.message ??= error.statusText;
	const nuxtError = createH3Error<DataT>(error) as NuxtError<DataT>;
	Object.defineProperty(nuxtError, NUXT_ERROR_SIGNATURE, { value: true, configurable: false, writable: false });
	Object.defineProperty(nuxtError, 'status', { get: () => nuxtError.statusCode, configurable: true });
	Object.defineProperty(nuxtError, 'statusText', { get: () => nuxtError.statusMessage, configurable: true });
	return nuxtError;
}

// --- utils/hash.js, utils/debounce-tick.js ------------------------------------------------------------

export function hashKey(value: unknown): string {
	return fnv1a64Base36(identify(value));
}

const NATIVE_CODE_RE = /\{\s*\[native code\]\s*\}/;

function hashFunction(fn: (...args: any[]) => unknown): string {
	const src = Function.prototype.toString.call(fn);
	return fnv1a64Base36(NATIVE_CODE_RE.test(src) ? `${fn.name || ''}(${fn.length})[native]` : src);
}

function debounceTick<A extends unknown[], R>(fn: (...args: A) => R): (...args: A) => Promise<Awaited<R>> {
	let active = false;
	let resolveList: ((value: any) => void)[] = [];
	let currentPromise: Promise<any> | undefined;
	let trailingArgs: A | undefined;
	const applyFn = (self: unknown, args: A): Promise<any> => {
		const promise = (async () => await fn.apply(self, args))();
		currentPromise = promise;
		promise.finally(() => {
			currentPromise = undefined;
			if (trailingArgs && !active) {
				const next = trailingArgs;
				trailingArgs = undefined;
				applyFn(self, next);
			}
		});
		return promise;
	};
	return function (this: unknown, ...args: A) {
		trailingArgs = args;
		if (currentPromise) return currentPromise;
		return new Promise((resolve) => {
			if (!active) {
				active = true;
				queuePostFlushCb(() => {
					active = false;
					const flushArgs = trailingArgs ?? args;
					trailingArgs = undefined;
					const promise = applyFn(this, flushArgs);
					for (const _resolve of resolveList) _resolve(promise);
					resolveList = [];
				});
			}
			resolveList.push(resolve);
		});
	};
}

// --- diagnostics/data.js : les avertissements de développement ---------------------------------------

const dataDiagnostics = {
	NUXT_E3001: (url: string) => new Error(`[NUXT_E3001] The \`useFetch\` request URL must not start with "//" (received \`${url}\`).`),
	NUXT_E3003: (nuxtApp: NuxtApp) => devWarning(nuxtApp, 'NUXT_E3003', '`useAsyncData`/`useFetch` was called after the component had already mounted, so the data fetch cannot be awaited during setup.', 'Use `$fetch()` for requests triggered after mount (e.g. in event handlers), or call `useAsyncData`/`useFetch` in the `setup()` function.'),
	NUXT_E3004: (nuxtApp: NuxtApp, key: string, warnings: string) => devWarning(nuxtApp, 'NUXT_E3004', `Incompatible options detected for "${key}":\n${warnings}`, 'You can use a different key or move the call to a composable to ensure the options are shared across calls.'),
	NUXT_E3005: (nuxtApp: NuxtApp) => devWarning(nuxtApp, 'NUXT_E3005', '`execute` was passed directly to `watch`, which causes unintended behavior.', 'Wrap the call: `watch(source, () => execute())` instead of `watch(source, execute)`.'),
	NUXT_E3006: (nuxtApp: NuxtApp, fn: string) => devWarning(nuxtApp, 'NUXT_E3006', `\`${fn}\` handler returned \`undefined\`, so the request may be duplicated on the client side.`, 'Return a value from the handler function (e.g. `return null` instead of returning nothing).'),
	NUXT_E3008: () => new Error('[NUXT_E3008] `useAsyncData` key must be a non-empty string.'),
	NUXT_E3009: () => new Error('[NUXT_E3009] `useAsyncData` handler must be a function.'),
};

// --- asyncData.js ----------------------------------------------------------------------------------

export type AsyncDataStatus = 'idle' | 'pending' | 'success' | 'error';

interface AsyncDataEntry {
	data: any;
	pending: any;
	error: any;
	status: any;
	execute: (...args: any[]) => Promise<any>;
	_execute: (...args: any[]) => Promise<any>;
	_default: () => unknown;
	_deps: number;
	_init: boolean;
	_hash?: Record<string, string | undefined>;
	_off: () => void;
	_abortController?: AbortController;
	_initialCachedData?: unknown;
}

function createUseAsyncData(options: Record<string, any> = {}) {
	return function useAsyncData(...args: any[]): any {
		const autoKey = typeof args[args.length - 1] === 'string' ? args.pop() : undefined;
		if (_isAutoKeyNeeded(args[0], args[1])) args.unshift(autoKey);
		const [_key, _handler, opts = {}] = args as [any, any, Record<string, any>];
		let keyChanging = false;
		const isKeyReactive = isRef(_key) || typeof _key === 'function';
		const key = isKeyReactive ? computed(() => toValue(_key)) : { value: _key };
		if (!key.value || typeof key.value !== 'string') throw dataDiagnostics.NUXT_E3008();
		if (typeof _handler !== 'function') throw dataDiagnostics.NUXT_E3009();
		const nuxtApp = useNuxtApp();
		for (const name in options) {
			if (options[name] === undefined) continue;
			if (opts[name] !== undefined) continue;
			opts[name] = options[name];
		}
		opts.server ??= true;
		opts.default ??= getDefault;
		opts.getCachedData ??= getDefaultCachedData;
		opts.lazy ??= false;
		opts.immediate ??= true;
		opts.deep ??= asyncDataDefaults.deep;
		opts.dedupe ??= 'cancel';
		opts.enabled ??= true;
		const currentData = nuxtApp._asyncData[key.value];
		if (currentData) {
			const warnings: string[] = [];
			const values = createHash(_handler, opts);
			if (values.handler !== currentData._hash?.handler) warnings.push('different handler');
			for (const opt of ['transform', 'pick', 'getCachedData']) if (values[opt] !== currentData._hash?.[opt]) warnings.push(`different \`${opt}\` option`);
			if (currentData._default.toString() !== opts.default.toString()) warnings.push('different `default` value');
			if (opts.deep && isShallow(currentData.data)) warnings.push('mismatching `deep` option');
			if (warnings.length) dataDiagnostics.NUXT_E3004(nuxtApp, key.value, warnings.map((w) => `- ${w}`).join('\n'));
		}
		function createInitialFetch() {
			const initialFetchOptions: Record<string, any> = { cause: 'initial', dedupe: opts.dedupe };
			const existing = nuxtApp._asyncData[key.value];
			if (!existing?._init) {
				initialFetchOptions.cachedData = opts.getCachedData(key.value, nuxtApp, { cause: 'initial' });
				nuxtApp._asyncData[key.value] = buildAsyncData(nuxtApp, key.value, _handler, opts, initialFetchOptions.cachedData);
				nuxtApp._asyncData[key.value]._initialCachedData = initialFetchOptions.cachedData;
			} else if (nuxtApp._asyncDataPromises[key.value]) initialFetchOptions.cachedData = existing._initialCachedData;
			return () => nuxtApp._asyncData[key.value].execute(initialFetchOptions);
		}
		const initialFetch = createInitialFetch();
		const asyncData = nuxtApp._asyncData[key.value];
		asyncData._deps++;
		const fetchOnServer = opts.server !== false && nuxtApp.payload.serverRendered;
		if (isServer && fetchOnServer && opts.immediate) {
			const promise = initialFetch();
			if (getCurrentInstance()) onServerPrefetch(() => promise);
			else nuxtApp.hook('app:created', async () => {
				await promise;
			});
		}
		if (!isServer) {
			const instance = getCurrentInstance() as any;
			if (instance && fetchOnServer && opts.immediate && !instance.sp) instance.sp = [];
			if (!nuxtApp.isHydrating && (!instance || instance?.isMounted)) dataDiagnostics.NUXT_E3003(nuxtApp);
			if (instance && !instance._nuxtOnBeforeMountCbs) {
				instance._nuxtOnBeforeMountCbs = [];
				const cbs: (() => void)[] = instance._nuxtOnBeforeMountCbs;
				onBeforeMount(() => {
					cbs.forEach((cb) => {
						cb();
					});
					cbs.splice(0, cbs.length);
				});
				onUnmounted(() => cbs.splice(0, cbs.length));
			}
			const isWithinClientOnly = instance && (instance._nuxtClientOnly || inject(Symbol.for('nuxt:client-only'), false));
			if (fetchOnServer && nuxtApp.isHydrating && (asyncData.error.value || asyncData.data.value !== undefined)) {
				asyncData.status.value = asyncData.error.value ? 'error' : 'success';
			} else if (instance && ((!isWithinClientOnly && nuxtApp.payload.serverRendered && nuxtApp.isHydrating) || opts.lazy) && opts.immediate) instance._nuxtOnBeforeMountCbs.push(initialFetch);
			else if (opts.immediate && asyncData.status.value !== 'success') initialFetch();
			const unregister = (oldKey: string) => {
				const data = nuxtApp._asyncData[oldKey];
				if (data?._deps) {
					data._deps--;
					if (data._deps === 0) data?._off();
				}
			};
			const hasScope = getCurrentScope();
			const noop = () => {};
			const unsubKeyWatcher = isKeyReactive ? watch(key as any, (newKey: string, oldKey: string) => {
				if ((newKey || oldKey) && newKey !== oldKey) {
					keyChanging = true;
					const hadData = nuxtApp._asyncData[oldKey]?.data.value !== undefined;
					const wasRunning = nuxtApp._asyncDataPromises[oldKey] !== undefined;
					const initialFetchOptions: Record<string, any> = { cause: 'initial', dedupe: opts.dedupe };
					if (!nuxtApp._asyncData[newKey]?._init) {
						const cachedData = opts.getCachedData(newKey, nuxtApp, { cause: 'initial' });
						initialFetchOptions.cachedData = cachedData;
						const initialValue = oldKey && hadData && cachedData === undefined ? nuxtApp._asyncData[oldKey].data.value : cachedData;
						nuxtApp._asyncData[newKey] = buildAsyncData(nuxtApp, newKey, _handler, opts, initialValue);
						nuxtApp._asyncData[newKey]._initialCachedData = cachedData;
					}
					nuxtApp._asyncData[newKey]._deps++;
					if (oldKey) unregister(oldKey);
					if (opts._keyTriggersExecute !== false && (opts.immediate || hadData || wasRunning)) nuxtApp._asyncData[newKey].execute(initialFetchOptions);
					queuePostFlushCb(() => {
						keyChanging = false;
					});
				}
			}, { flush: 'sync' }) : noop;
			const unsubParamsWatcher = opts.watch ? watch(opts.watch, () => {
				if (keyChanging) return;
				nuxtApp._asyncData[key.value]?._execute({ cause: 'watch', dedupe: opts.dedupe });
			}) : noop;
			const unsubEnabledWatcher = isRef(opts.enabled) || typeof opts.enabled === 'function' ? watch(() => toValue(opts.enabled), (isEnabled) => {
				const entry = nuxtApp._asyncData[key.value];
				if (isEnabled || !entry || !nuxtApp._asyncDataPromises[key.value]) return;
				entry._abortController?.abort(new DOMException('AsyncData request cancelled by `enabled: false`', 'AbortError'));
				entry._abortController = undefined;
				delete nuxtApp._asyncDataPromises[key.value];
				entry.status.value = 'idle';
			}) : noop;
			if (hasScope) onScopeDispose(() => {
				unsubKeyWatcher();
				unsubParamsWatcher();
				unsubEnabledWatcher();
				unregister(key.value);
			});
		}
		const asyncReturn: Record<string, any> = {
			data: writableComputedRef(() => nuxtApp._asyncData[key.value]?.data),
			pending: writableComputedRef(() => nuxtApp._asyncData[key.value]?.pending),
			status: writableComputedRef(() => nuxtApp._asyncData[key.value]?.status),
			error: writableComputedRef(() => nuxtApp._asyncData[key.value]?.error),
			refresh: (...refreshArgs: any[]) => {
				if (!nuxtApp._asyncData[key.value]?._init) return createInitialFetch()();
				return nuxtApp._asyncData[key.value].execute(...refreshArgs);
			},
			execute: (...executeArgs: any[]) => asyncReturn.refresh(...executeArgs),
			clear: () => {
				const entry = nuxtApp._asyncData[key.value];
				if (entry?._abortController) try {
					entry._abortController.abort(new DOMException('AsyncData aborted by user.', 'AbortError'));
				} finally {
					entry._abortController = undefined;
				}
				clearNuxtDataByKey(nuxtApp, key.value);
			},
		};
		const asyncDataPromise = Promise.resolve(nuxtApp._asyncDataPromises[key.value]).then(() => asyncReturn);
		Object.assign(asyncDataPromise, asyncReturn);
		Object.defineProperties(asyncDataPromise, {
			then: { enumerable: true, value: asyncDataPromise.then.bind(asyncDataPromise) },
			catch: { enumerable: true, value: asyncDataPromise.catch.bind(asyncDataPromise) },
			finally: { enumerable: true, value: asyncDataPromise.finally.bind(asyncDataPromise) },
		});
		return asyncDataPromise;
	};
}

export const useAsyncData = createUseAsyncData();
export const useLazyAsyncData = createUseAsyncData({ lazy: true, _functionName: 'useLazyAsyncData' });

function writableComputedRef(getter: () => any) {
	return computed({
		get() {
			return getter()?.value;
		},
		set(value) {
			const target = getter();
			if (target) target.value = value;
		},
	});
}

function _isAutoKeyNeeded(keyOrFetcher: unknown, fetcher: unknown): boolean {
	if (typeof keyOrFetcher === 'string') return false;
	if (typeof keyOrFetcher === 'object' && keyOrFetcher !== null) return false;
	if (typeof keyOrFetcher === 'function' && typeof fetcher === 'function') return false;
	return true;
}

export function useNuxtData(key: string) {
	const nuxtApp = useNuxtApp();
	if (!(key in nuxtApp.payload.data)) nuxtApp.payload.data[key] = undefined;
	if (nuxtApp._asyncData[key]) {
		const data = nuxtApp._asyncData[key];
		data._deps++;
		if (getCurrentScope()) onScopeDispose(() => {
			data._deps--;
			if (data._deps === 0) data?._off();
		});
	}
	return {
		data: computed({
			get() {
				return nuxtApp._asyncData[key]?.data.value ?? nuxtApp.payload.data[key];
			},
			set(value) {
				if (nuxtApp._asyncData[key]) nuxtApp._asyncData[key].data.value = value;
				else nuxtApp.payload.data[key] = value;
			},
		}),
	};
}

export async function refreshNuxtData(keys?: string | string[]): Promise<void> {
	if (isServer) return Promise.resolve();
	const nuxtApp = useNuxtApp();
	if (nuxtApp.isHydrating) await new Promise<void>((resolve) => nuxtApp.hooks.hookOnce('app:suspense:resolve', () => resolve()));
	const _keys = keys ? (Array.isArray(keys) ? keys : [keys]) : undefined;
	await nuxtApp.hooks.callHookParallel('app:data:refresh', _keys);
}

export function clearNuxtData(keys?: string | string[] | ((key: string) => boolean)): void {
	const nuxtApp = useNuxtApp();
	const _allKeys = Object.keys(nuxtApp.payload.data);
	const _keys = !keys ? _allKeys : typeof keys === 'function' ? _allKeys.filter(keys) : Array.isArray(keys) ? keys : [keys];
	for (const key of _keys) clearNuxtDataByKey(nuxtApp, key);
}

function clearNuxtDataByKey(nuxtApp: NuxtApp, key: string): void {
	delete nuxtApp.payload.data[key];
	delete nuxtApp.payload._errors[key];
	if (nuxtApp._asyncData[key]) {
		nuxtApp._asyncData[key].data.value = unref(nuxtApp._asyncData[key]._default());
		nuxtApp._asyncData[key].error.value = undefined;
		nuxtApp._asyncData[key].status.value = 'idle';
		nuxtApp._asyncData[key]._initialCachedData = undefined;
	}
	delete nuxtApp._asyncDataPromises[key];
}

function pick(obj: Record<string, unknown>, keys: string[]): Record<string, unknown> {
	const newObj: Record<string, unknown> = {};
	for (const key of keys) newObj[key] = obj[key];
	return newObj;
}

function buildAsyncData(nuxtApp: NuxtApp, key: string, _handler: (nuxtApp: NuxtApp, options: { signal: AbortSignal }) => unknown, options: Record<string, any>, initialCachedData: unknown): AsyncDataEntry {
	nuxtApp.payload._errors[key] ??= undefined;
	const hasCustomGetCachedData = options.getCachedData !== getDefaultCachedData;
	const handler = _handler;
	const _ref = options.deep ? ref : shallowRef;
	const hasCachedData = initialCachedData !== undefined;
	const unsubRefreshAsyncData = nuxtApp.hook('app:data:refresh', async (keys?: string[]) => {
		if (!keys || keys.includes(key)) await asyncData.execute({ cause: 'refresh:hook' });
	});
	const asyncData: AsyncDataEntry = {
		data: _ref(hasCachedData ? initialCachedData : options.default()),
		pending: computed(() => asyncData.status.value === 'pending'),
		error: toRef(nuxtApp.payload._errors, key),
		status: shallowRef<AsyncDataStatus>('idle'),
		execute: (...args: any[]) => {
			const [_opts, newValue = undefined] = args;
			const opts = _opts && newValue === undefined && typeof _opts === 'object' ? _opts : {};
			if (newValue !== undefined && (!_opts || typeof _opts !== 'object')) dataDiagnostics.NUXT_E3005(nuxtApp);
			if (nuxtApp._asyncDataPromises[key]) {
				if ((opts.dedupe ?? options.dedupe) === 'defer') return nuxtApp._asyncDataPromises[key];
			}
			if (granularCachedData || opts.cause === 'initial' || nuxtApp.isHydrating) {
				const cachedData = 'cachedData' in opts ? opts.cachedData : options.getCachedData(key, nuxtApp, { cause: opts.cause ?? 'refresh:manual' });
				if (cachedData !== undefined) {
					nuxtApp.payload.data[key] = asyncData.data.value = cachedData;
					asyncData.error.value = undefined;
					asyncData.status.value = 'success';
					return Promise.resolve(cachedData);
				}
			}
			if (toValue(options.enabled) === false) return Promise.resolve(asyncData.data.value);
			if (asyncData._abortController) asyncData._abortController.abort(new DOMException('AsyncData request cancelled by deduplication', 'AbortError'));
			asyncData._abortController = new AbortController();
			asyncData.status.value = 'pending';
			const cleanupController = new AbortController();
			const promise: Promise<any> = new Promise((resolve, reject) => {
				try {
					const timeout = opts.timeout ?? options.timeout;
					const mergedSignal = mergeAbortSignals([asyncData._abortController?.signal, opts?.signal], cleanupController.signal, timeout);
					if (mergedSignal.aborted) {
						const reason = mergedSignal.reason;
						reject(reason instanceof Error ? reason : new DOMException(String(reason ?? 'Aborted'), 'AbortError'));
						return;
					}
					mergedSignal.addEventListener('abort', () => {
						const reason = mergedSignal.reason;
						reject(reason instanceof Error ? reason : new DOMException(String(reason ?? 'Aborted'), 'AbortError'));
					}, { once: true, signal: cleanupController.signal });
					return Promise.resolve(handler(nuxtApp, { signal: mergedSignal })).then(resolve, reject);
				} catch (err) {
					reject(err);
				}
			}).then(async (_result) => {
				if (nuxtApp._asyncDataPromises[key] !== promise) return;
				let result = _result;
				if (options.transform) result = await options.transform(_result);
				if (options.pick) result = pick(result as Record<string, unknown>, options.pick);
				if (isServer && typeof result === 'undefined') dataDiagnostics.NUXT_E3006(nuxtApp, options._functionName || 'useAsyncData');
				nuxtApp.payload.data[key] = result;
				asyncData.data.value = result;
				asyncData.error.value = undefined;
				asyncData.status.value = 'success';
			}).catch((error) => {
				if (nuxtApp._asyncDataPromises[key] !== promise) return nuxtApp._asyncDataPromises[key];
				if (asyncData._abortController?.signal.aborted) return nuxtApp._asyncDataPromises[key];
				if (typeof DOMException !== 'undefined' && error instanceof DOMException && error.name === 'AbortError') {
					asyncData.status.value = 'idle';
					return nuxtApp._asyncDataPromises[key];
				}
				asyncData.error.value = createError(error);
				asyncData.data.value = unref(options.default());
				asyncData.status.value = 'error';
			}).finally(() => {
				cleanupController.abort();
				if (nuxtApp._asyncDataPromises[key] === promise) {
					delete nuxtApp._asyncDataPromises[key];
				}
			});
			nuxtApp._asyncDataPromises[key] = promise;
			return nuxtApp._asyncDataPromises[key];
		},
		_execute: debounceTick((...args: any[]) => asyncData.execute(...args)),
		_default: options.default,
		_deps: 0,
		_init: true,
		_hash: createHash(_handler, options),
		_off: () => {
			unsubRefreshAsyncData();
			if (nuxtApp._asyncData[key]?._init) nuxtApp._asyncData[key]._init = false;
			if (nuxtApp._asyncDataPromises[key]) {
				asyncData._abortController?.abort(new DOMException('AsyncData request cancelled by unmount', 'AbortError'));
				delete nuxtApp._asyncDataPromises[key];
				if (asyncData.status.value === 'pending') asyncData.status.value = 'idle';
			}
			if (purgeCachedData && !hasCustomGetCachedData) nextTick(() => {
				if (!nuxtApp._asyncData[key]?._init) {
					clearNuxtDataByKey(nuxtApp, key);
					asyncData.execute = () => Promise.resolve();
				}
			});
		},
	};
	return asyncData;
}

const getDefault = () => undefined;

const getDefaultCachedData = (key: string, nuxtApp: NuxtApp, ctx: { cause?: string }) => {
	if (nuxtApp.isHydrating) return nuxtApp.payload.data[key];
	if (ctx.cause !== 'refresh:manual' && ctx.cause !== 'refresh:hook') return nuxtApp.static.data[key];
};

function createHash(_handler: (...args: any[]) => unknown, options: Record<string, any>): Record<string, string | undefined> {
	return {
		handler: hashFunction(_handler),
		transform: options.transform ? hashFunction(options.transform) : undefined,
		pick: options.pick ? hashKey(options.pick) : undefined,
		getCachedData: options.getCachedData ? hashFunction(options.getCachedData) : undefined,
	};
}

function mergeAbortSignals(signals: (AbortSignal | undefined)[], cleanupSignal: AbortSignal, timeout?: number): AbortSignal {
	const list = signals.filter((s): s is AbortSignal => !!s);
	if (typeof timeout === 'number' && timeout >= 0) {
		const timeoutSignal = AbortSignal.timeout?.(timeout);
		if (timeoutSignal) list.push(timeoutSignal);
	}
	if (AbortSignal.any) return AbortSignal.any(list);
	const controller = new AbortController();
	for (const sig of list) if (sig.aborted) {
		controller.abort(sig.reason ?? new DOMException('Aborted', 'AbortError'));
		return controller.signal;
	}
	const onAbort = () => controller.abort(list.find((s) => s.aborted)?.reason ?? new DOMException('Aborted', 'AbortError'));
	for (const sig of list) sig.addEventListener?.('abort', onAbort, { once: true, signal: cleanupSignal });
	return controller.signal;
}

// --- fetch.js --------------------------------------------------------------------------------------

const MAYBE_REF_OR_GETTER_OPTION_KEYS = ['method', 'baseURL', 'query', 'params', 'body', 'headers'];

function isPlainObject(value: unknown): value is Record<string, unknown> {
	return Object.prototype.toString.call(value) === '[object Object]';
}

function generateOptionSegments(opts: Record<string, any>): unknown[] {
	const segments: unknown[] = [toValue(opts.method)?.toUpperCase() || 'GET', toValue(opts.baseURL)];
	for (const _obj of [opts.query || opts.params]) {
		const obj = toValue(_obj);
		if (!obj) continue;
		const unwrapped: Record<string, unknown> = {};
		for (const [key, value] of Object.entries(obj)) unwrapped[toValue(key)] = toValue(value);
		segments.push(unwrapped);
	}
	if (opts.body) {
		const value = toValue(opts.body);
		if (!value) segments.push(hashKey(value));
		else if (value instanceof ArrayBuffer) segments.push(hashKey(Object.fromEntries([...new Uint8Array(value).entries()].map(([k, v]) => [k, v.toString()]))));
		else if (typeof FormData !== 'undefined' && value instanceof FormData) {
			const entries: unknown[] = [];
			for (const [name, val] of value.entries()) entries.push([name, typeof File !== 'undefined' && val instanceof File ? `${val.name}:${val.size}:${val.lastModified}` : val]);
			segments.push(hashKey(entries));
		} else if (isPlainObject(value)) segments.push(hashKey(reactive(value)));
		else try {
			segments.push(hashKey(value));
		} catch {
			// NUXT_E3002 : corps impossible à hacher, la clé s'en passe.
		}
	}
	return segments;
}

function createUseFetch(factoryOptions: Record<string, any> = {}) {
	return function useFetch(request: any, arg1?: any, arg2?: any): any {
		const [opts = {}, autoKey] = typeof arg1 === 'string' ? [{}, arg1] : [arg1, arg2];
		const { server, lazy, default: defaultFn, transform, pick: pickKeys, watch: watchSources, immediate, getCachedData, deep, dedupe, timeout, enabled, ...fetchOptions } = {
			...factoryOptions,
			...opts,
		};
		const _request = computed(() => toValue(request));
		const key = computed(() => toValue(fetchOptions.key) || '$f' + hashKey([
			autoKey,
			typeof _request.value === 'string' ? _request.value : '',
			...generateOptionSegments(fetchOptions),
		]));
		if (!fetchOptions.baseURL && typeof _request.value === 'string' && _request.value[0] === '/' && _request.value[1] === '/') throw dataDiagnostics.NUXT_E3001(_request.value);
		const _fetchOptions = reactive({
			...fetchOptions,
			cache: typeof fetchOptions.cache === 'boolean' ? undefined : fetchOptions.cache,
		});
		const _asyncDataOptions: Record<string, any> = {
			server,
			lazy,
			default: defaultFn,
			transform,
			pick: pickKeys,
			immediate,
			getCachedData,
			deep,
			dedupe,
			timeout,
			enabled,
			watch: watchSources === false ? [] : [...(watchSources || []), _fetchOptions],
		};
		_asyncDataOptions._functionName ||= factoryOptions._functionName || 'useFetch';
		if (watchSources === false) _asyncDataOptions._keyTriggersExecute = false;
		return useAsyncData(key, (nuxtApp: NuxtApp, { signal }: { signal: AbortSignal }) => {
			// Nuxt lit ici le `$fetch` global, celui de Nitro côté serveur. Le simulateur, qui peut faire tourner
			// plusieurs serveurs dans un même processus, le prend dans le contexte de rendu de l'application.
			let _$fetch: $Fetch = fetchOptions.$fetch || (isServer && nuxtApp.ssrContext?.$fetch) || $fetch;
			if (isServer && !fetchOptions.$fetch) {
				if (typeof _request.value === 'string' && _request.value[0] === '/' && (!toValue(fetchOptions.baseURL) || toValue(fetchOptions.baseURL)[0] === '/')) _$fetch = useRequestFetch();
			}
			const resolvedOptions: Record<string, any> = { signal, ..._fetchOptions };
			for (const name of MAYBE_REF_OR_GETTER_OPTION_KEYS) if (typeof resolvedOptions[name] === 'function') resolvedOptions[name] = toValue(resolvedOptions[name]);
			return _$fetch(_request.value, resolvedOptions);
		}, _asyncDataOptions);
	};
}

export const useFetch = createUseFetch();
export const useLazyFetch = createUseFetch({ lazy: true, _functionName: 'useLazyFetch' });

// --- once.js ---------------------------------------------------------------------------------------

export async function callOnce(...args: any[]): Promise<void> {
	const autoKey = typeof args[args.length - 1] === 'string' ? args.pop() : undefined;
	if (typeof args[0] !== 'string') args.unshift(autoKey);
	const [_key, fn, options] = args;
	if (!_key || typeof _key !== 'string') throw new TypeError(`[nuxt] [callOnce] key must be a string: ${_key}`);
	if (fn !== undefined && typeof fn !== 'function') throw new Error(`[nuxt] [callOnce] fn must be a function: ${fn}`);
	const nuxtApp = useNuxtApp();
	if (options?.mode === 'navigation') {
		const removeGuard = nuxtApp.router.beforeResolve(() => {
			nuxtApp.payload.once.delete(_key);
			removeGuard();
		});
	}
	if (nuxtApp.payload.once.has(_key)) return;
	nuxtApp._once ||= {};
	nuxtApp._once[_key] ||= fn() || true;
	try {
		await nuxtApp._once[_key];
	} catch (e) {
		delete nuxtApp._once[_key];
		throw e;
	}
	nuxtApp.payload.once.add(_key);
	delete nuxtApp._once[_key];
}
