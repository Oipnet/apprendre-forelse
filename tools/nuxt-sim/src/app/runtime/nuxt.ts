/**
 * Cœur du runtime d'application, commun au serveur simulé et au navigateur : le même code rend la page
 * puis l'hydrate, condition pour que l'hydratation retrouve exactement le HTML servi.
 *
 * Ce fichier et ses voisins ne dépendent que de `vue` et `vue-router` : côté navigateur, ils sont
 * empaquetés avec eux dans un seul module (voir ../client-bundle.ts).
 */
import { getCurrentInstance, hasInjectionContext, inject, isRef, reactive, toRef, type App, type Component, type InjectionKey, type Ref } from 'vue';
import { useRoute as useRouterRoute, useRouter as useVueRouter, type RouteLocationNormalizedLoaded, type Router } from 'vue-router';
import type { Hookable } from 'hookable';

/** Un Web Worker n'a pas de `document` : c'est le serveur simulé. L'aperçu, lui, en a un. */
export const isServer = typeof document === 'undefined';

export const PageRouteSymbol: InjectionKey<RouteLocationNormalizedLoaded> = Symbol('route');
export const LayoutSymbol: InjectionKey<unknown> = Symbol('layout');
export const LayoutMetaSymbol: InjectionKey<{ isCurrent: (route: RouteLocationNormalizedLoaded) => boolean }> = Symbol('layout-meta');

export interface NuxtPayload {
	data: Record<string, unknown>;
	state: Record<string, unknown>;
	once: Set<string>;
	_errors: Record<string, unknown>;
	serverRendered?: boolean;
	path?: string;
}

/** Message du serveur de développement, transmis à la page dans <script data-nuxt-logs> (dev-server-logs de Nuxt). */
export interface NuxtLog {
	date: Date;
	args: unknown[];
	type: string;
	level: number;
	tag: string;
	filename: string;
	stack: unknown[];
}

export interface NuxtApp {
	vueApp: App;
	router: Router;
	isServer: boolean;
	isHydrating: boolean;
	layouts: Record<string, Component>;
	payload: NuxtPayload;
	runtimeConfig: Record<string, any>;
	/** Avertissements émis pendant le rendu serveur. */
	logs: NuxtLog[];
	/** Valeurs initiales des useState, pour clearNuxtState. */
	_state: Record<string, { _default: () => unknown }>;
	/** app.config.ts tel que chargé ; `useAppConfig()` en fait une copie par application. */
	appConfig: Record<string, any>;
	_appConfig?: Record<string, any>;
	hooks: Hookable<Record<string, any>>;
	hook: Hookable<Record<string, any>>['hook'];
	callHook: Hookable<Record<string, any>>['callHook'];
	static: { data: Record<string, unknown> };
	/** useAsyncData : une entrée par clé, et la requête en cours. */
	_asyncData: Record<string, any>;
	_asyncDataPromises: Record<string, Promise<any> | undefined>;
	_once?: Record<string, unknown>;
	/** Côté serveur : la requête rendue, et le `$fetch` de Nitro qui répond sans réseau. */
	ssrContext?: { url: string; event: any; $fetch: any };
}

/**
 * Diagnostic de développement de Nuxt (nostics) : « [CODE] pourquoi » puis « ╰▶ fix: … ». Côté serveur,
 * il rejoint le journal de la page ; dans le navigateur, la console.
 */
export function devWarning(nuxtApp: NuxtApp, code: string, why: string, fix?: string): void {
	const message = fix ? `[${code}] ${why}\n╰▶ fix: ${fix}` : `[${code}] ${why}`;
	// Nuxt ajoute « ╰▶ sources: » (un fichier interne de Nuxt) : le simulateur ne le connaît pas et s'en passe.
	if (nuxtApp.isServer) {
		nuxtApp.logs.push({ date: new Date(), args: [message], type: 'warn', level: 1, tag: '', filename: '', stack: [] });
	} else {
		console.warn(message);
	}
}

let currentNuxtApp: NuxtApp | undefined;

/** Exécute `fn` avec l'instance Nuxt disponible hors d'un setup (plugins, middleware…), comme nuxtApp.runWithContext. */
export function runWithContext<T>(nuxtApp: NuxtApp, fn: () => T): T {
	const previous = currentNuxtApp;
	currentNuxtApp = nuxtApp;
	try {
		return fn();
	} finally {
		currentNuxtApp = previous;
	}
}

export function useNuxtApp(): NuxtApp {
	const nuxtApp = (getCurrentInstance()?.appContext.app.config.globalProperties.$nuxt as NuxtApp | undefined) ?? currentNuxtApp;
	if (!nuxtApp) {
		// Diagnostic de Nuxt (app/diagnostics/core) : même message, levé dès l'appel hors contexte.
		throw new Error('A composable that requires access to the Nuxt instance was called outside of a plugin, Nuxt hook, Nuxt middleware, or Vue setup function. This is probably not a Nuxt bug.');
	}
	return nuxtApp;
}

export function useRouter(): Router {
	return (hasInjectionContext() ? useVueRouter() : undefined) ?? useNuxtApp().router;
}

/** Dans une page, la route de cette page (elle reste stable pendant une transition) ; ailleurs, celle du routeur. */
export function useRoute(): RouteLocationNormalizedLoaded {
	if (hasInjectionContext()) {
		return inject(PageRouteSymbol, useRouterRoute());
	}
	return useNuxtApp().router.currentRoute.value;
}

/** Macro lue à la construction (voir ../pages.ts) : à l'exécution, elle ne fait rien. */
export function definePageMeta(_meta: Record<string, unknown>): void {}

export function useRuntimeConfig(): Record<string, any> {
	return useNuxtApp().runtimeConfig;
}

// --- useState (app/composables/state) --------------------------------------------------------

const STATE_KEY_PREFIX = '$s';

export function useState<T>(...args: unknown[]): Ref<T> {
	const autoKey = typeof args[args.length - 1] === 'string' ? (args.pop() as string) : undefined;
	if (typeof args[0] !== 'string') args.unshift(autoKey);
	const [_key, init] = args as [string | undefined, (() => T | Ref<T>) | undefined];
	if (!_key || typeof _key !== 'string') {
		throw new TypeError(`[nuxt] [useState] key must be a string: ${_key}`);
	}
	if (init !== undefined && typeof init !== 'function') {
		throw new Error(`[nuxt] [useState] init must be a function: ${init}`);
	}
	const key = STATE_KEY_PREFIX + _key;
	const nuxtApp = useNuxtApp();
	const state = toRef(nuxtApp.payload.state, key) as Ref<T>;
	if (init) nuxtApp._state[key] ??= { _default: init };
	if (state.value === undefined && init) {
		const initialValue = init();
		if (isRef(initialValue)) {
			nuxtApp.payload.state[key] = initialValue;
			return initialValue as Ref<T>;
		}
		state.value = initialValue;
	}
	return state;
}

export function clearNuxtState(keys?: string | string[] | ((key: string) => boolean)): void {
	const nuxtApp = useNuxtApp();
	const allKeys = Object.keys(nuxtApp.payload.state).filter((key) => key.startsWith(STATE_KEY_PREFIX)).map((key) => key.substring(2));
	const selected = !keys ? allKeys : typeof keys === 'function' ? allKeys.filter(keys) : Array.isArray(keys) ? keys : [keys];
	for (const _key of selected) {
		delete nuxtApp.payload.state[STATE_KEY_PREFIX + _key];
	}
}

// --- app.config.ts (app/config) ------------------------------------------------------------

export function defineAppConfig<C extends Record<string, any>>(config: C): C {
	return config;
}

export function useAppConfig(): Record<string, any> {
	const nuxtApp = useNuxtApp();
	nuxtApp._appConfig ||= isServer ? structuredClone(nuxtApp.appConfig) : reactive(nuxtApp.appConfig);
	return nuxtApp._appConfig;
}
