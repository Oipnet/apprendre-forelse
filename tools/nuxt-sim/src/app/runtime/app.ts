/**
 * Création de l'application Vue d'une requête (serveur) ou de la page (navigateur), à la manière de
 * nuxt/dist/app/entry et du plugin de routeur de pages. Une application par requête côté serveur :
 * c'est ce qui rend visibles les fuites d'état entre visiteurs (chapitre 3 du parcours).
 */
import { createHead as createClientHead } from '@unhead/vue/client';
import { legacyPlugins } from '@unhead/vue/legacy';
import { parse } from 'devalue';
import { createHooks } from 'hookable';
import { isSamePath, withoutBase } from 'ufo';
import {
	Suspense, createApp, createSSRApp, defineAsyncComponent, defineComponent, h, isReactive, isReadonly, isRef, isShallow, onErrorCaptured, provide, reactive, ref, shallowReactive,
	shallowRef, toRaw, type Component,
} from 'vue';
import { createMemoryHistory, createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';
import { ClientOnly, NuxtLayout, NuxtLink, NuxtPage } from './components.ts';
import { clearError, createError, isNuxtError, showError, useError } from './data.ts';
import { PageRouteSymbol, isServer, runWithContext, useNuxtApp, useRoute, type NuxtApp, type NuxtPayload } from './nuxt.ts';
import { navigateTo, type RouteMiddleware } from './router.ts';

type Loader = () => Promise<{ default: Component }>;
type MiddlewareLoader = () => Promise<{ default: RouteMiddleware }>;

/** Options d'unhead que Nuxt 4 passe à createHead (#build/unhead-options.mjs). */
export const UNHEAD_OPTIONS = { disableDefaults: true, plugins: legacyPlugins };

export interface ManifestRoute {
	name?: string;
	path: string;
	meta?: Record<string, unknown>;
	component: Loader;
	children?: ManifestRoute[];
}

/** Ce que Nuxt produit à la construction dans #build : composant racine, layouts, routes des pages. */
export interface AppManifest {
	/** Faux sans dossier app/pages : Nuxt n'installe pas le routeur de pages, app.vue répond à toute URL. */
	pages: boolean;
	app?: Loader;
	/** Composants de app/components, par nom (auto-importés dans les templates, et en Lazy…). */
	components: Record<string, Loader>;
	/** app/app.config.ts */
	appConfig?: () => Promise<{ default?: Record<string, any> }>;
	layouts: Record<string, Loader>;
	routes: ManifestRoute[];
	/** app/error.vue : la page d'erreur du projet (sinon celle de Nuxt). */
	error?: Loader;
	/** app/middleware : globaux (dans l'ordre des fichiers) et nommés. */
	middleware?: { global: MiddlewareLoader[]; named: Record<string, MiddlewareLoader> };
	/** `app.head` de nuxt.config, complété (voir head.ts). */
	appHead?: Record<string, any>;
}

export interface CreateOptions {
	/** Côté serveur : l'URL demandée (chemin + query). */
	url?: string;
	runtimeConfig: Record<string, any>;
	payload?: NuxtPayload;
	/** Côté serveur : l'événement de la requête et le `$fetch` de Nitro (voir useRequestFetch). */
	ssrContext?: NuxtApp['ssrContext'];
	/** Journal du rendu serveur, déjà commencé par le rendu (sorties de la console). */
	logs?: NuxtApp['logs'];
	/** Environnement de test `nuxt` (@nuxt/test-utils) : la racine qui remplace NuxtRoot. */
	rootComponent?: Component;
}

/** `app.vue` par défaut de Nuxt quand le projet n'en a pas (pages/runtime/app.vue, sans NuxtRouteAnnouncer). */
const DefaultApp = defineComponent({
	name: 'NuxtApp',
	setup: () => () => h(NuxtLayout, null, { default: () => h(NuxtPage) }),
});

/** Page d'erreur de Nuxt quand le projet n'a pas de app/error.vue (simplifiée : error-404.vue et error-500.vue). */
const DefaultErrorPage = defineComponent({
	name: 'NuxtErrorPage',
	props: { error: Object },
	setup(props) {
		return () => {
			const error = props.error as Record<string, any>;
			const statusCode = Number(error?.statusCode || 500);
			return h('div', { class: 'nuxt-error-page' }, [h('h1', statusCode), h('p', error?.statusMessage || error?.message || (statusCode === 404 ? 'Page not found' : 'Internal server error'))]);
		};
	},
});

/** NuxtRoot (app/components/nuxt-root.vue) : la page d'erreur remplace l'application quand une erreur est montrée. */
function createNuxtRoot(appComponent: Component, errorComponent: Component) {
	return defineComponent({
		name: 'NuxtRoot',
		setup() {
			const nuxtApp = useNuxtApp();
			const onResolve = () => {
				if (!nuxtApp.isHydrating) return;
				nuxtApp.isHydrating = false;
				nuxtApp.callHook('app:suspense:resolve');
			};
			provide(PageRouteSymbol, useRoute());
			const error = useError();
			const abortRender = isServer && error.value && !nuxtApp.ssrContext?.error;
			onErrorCaptured((err, target, info) => {
				nuxtApp.hooks.callHook('vue:error', err, target, info)?.catch((hookError: unknown) => console.error('[nuxt] Error in `vue:error` hook', hookError));
				if (isServer || (isNuxtError(err) && (err.fatal || (err as { unhandled?: boolean }).unhandled))) {
					runWithContext(nuxtApp, () => showError(err));
					return false;
				}
			});
			return () => h(Suspense, { onResolve }, {
				default: () => (abortRender ? h('div') : error.value ? h(errorComponent, { error: error.value }) : h(appComponent)),
			});
		},
	});
}

function toRouteRecords(routes: ManifestRoute[]): RouteRecordRaw[] {
	return routes.map((route) => ({
		name: route.name,
		path: route.path,
		meta: route.meta ?? {},
		component: route.component,
		children: toRouteRecords(route.children ?? []),
	}) as RouteRecordRaw);
}

export function createPayload(): NuxtPayload {
	return shallowReactive({ data: shallowReactive({}), state: reactive({}), once: new Set<string>(), _errors: shallowReactive({}) }) as unknown as NuxtPayload;
}

/** Réducteurs de la charge utile (app/plugins/revive-payload.server), dans le même ordre. */
export const payloadReducers: Record<string, (value: unknown) => unknown> = {
	NuxtError: (data) => isNuxtError(data) && data.toJSON(),
	EmptyShallowRef: (data) => isRef(data) && isShallow(data) && !data.value && (typeof data.value === 'bigint' ? '0n' : JSON.stringify(data.value) || '_'),
	EmptyRef: (data) => isRef(data) && !data.value && (typeof data.value === 'bigint' ? '0n' : JSON.stringify(data.value) || '_'),
	ShallowRef: (data) => isRef(data) && isShallow(data) && data.value,
	ShallowReactive: (data) => isReactive(data) && isShallow(data) && toRaw(data),
	Ref: (data) => isRef(data) && data.value,
	Reactive: (data) => isReactive(data) && toRaw(data),
};

/** Et leurs inverses (app/plugins/revive-payload.client). */
const payloadRevivers: Record<string, (value: any) => unknown> = {
	NuxtError: (data) => createError(data),
	EmptyShallowRef: (data) => shallowRef(data === '_' ? undefined : data === '0n' ? 0n : JSON.parse(data)),
	EmptyRef: (data) => ref(data === '_' ? undefined : data === '0n' ? 0n : JSON.parse(data)),
	ShallowRef: (data) => shallowRef(data),
	ShallowReactive: (data) => shallowReactive(data),
	Ref: (data) => ref(data),
	Reactive: (data) => reactive(data),
};

export async function createNuxtApp(manifest: AppManifest, options: CreateOptions): Promise<NuxtApp> {
	const [appModule, layoutEntries, componentEntries, appConfigModule, errorModule, globalMiddleware] = await Promise.all([
		manifest.app?.(),
		Promise.all(Object.entries(manifest.layouts).map(async ([name, load]) => [name, (await load()).default] as const)),
		Promise.all(Object.entries(manifest.components).map(async ([name, load]) => [name, (await load()).default] as const)),
		manifest.appConfig?.(),
		manifest.error?.(),
		Promise.all((manifest.middleware?.global ?? []).map(async (load) => (await load()).default)),
	]);
	const rootComponent = options.rootComponent ?? createNuxtRoot(appModule?.default ?? DefaultApp, errorModule?.default ?? DefaultErrorPage);
	// entry.js : une page que le serveur n'a pas rendue (environnement de test) est montée, pas hydratée.
	const vueApp = !isServer && options.payload?.serverRendered === false ? createApp(rootComponent) : createSSRApp(rootComponent);
	const routerBase = options.runtimeConfig.app?.baseURL ?? '/';
	const router = createRouter({
		history: isServer ? createMemoryHistory(routerBase) : createWebHistory(routerBase),
		routes: manifest.pages ? toRouteRecords(manifest.routes) : [{ path: '/:pathMatch(.*)*', component: { render: () => null } }],
	});
	const hooks = createHooks<Record<string, any>>();
	const nuxtApp: NuxtApp = {
		vueApp,
		router,
		isServer,
		isHydrating: !isServer,
		layouts: Object.fromEntries(layoutEntries),
		// Côté serveur, ce que le rendu a déjà mis dans la charge utile (l'erreur d'une page d'erreur) passe en tête.
		payload: options.payload ?? (shallowReactive({ ...(options.ssrContext?.payload ?? {}), data: shallowReactive({}), state: reactive({}), once: new Set<string>(), _errors: shallowReactive({}) }) as unknown as NuxtPayload),
		runtimeConfig: options.runtimeConfig,
		logs: options.logs ?? [],
		_state: {},
		appConfig: appConfigModule?.default ?? {},
		hooks,
		hook: hooks.hook,
		callHook: hooks.callHook,
		static: { data: {} },
		_asyncData: shallowReactive({}),
		_asyncDataPromises: {},
		ssrContext: options.ssrContext,
		_middleware: { global: [], named: {} },
	};
	if (isServer) {
		nuxtApp.payload.serverRendered = true;
		if (options.ssrContext) nuxtApp.payload.path = options.ssrContext.url;
		// Côté serveur, Nuxt appelle chaque hook avec l'instance disponible (useNuxtApp y fonctionne).
		const contextCaller = async (list: ((...args: any[]) => unknown)[], args: unknown[]) => {
			for (const hook of list) await runWithContext(nuxtApp, () => hook(...args));
		};
		hooks.callHook = (name: string, ...args: any[]) => hooks.callHookWith(contextCaller as never, name, args as never) as never;
		nuxtApp.callHook = hooks.callHook;
	}
	vueApp.config.globalProperties.$nuxt = nuxtApp;
	vueApp.component('NuxtPage', NuxtPage);
	vueApp.component('NuxtLayout', NuxtLayout);
	vueApp.component('NuxtLink', NuxtLink);
	vueApp.component('ClientOnly', ClientOnly);
	for (const [name, component] of componentEntries) {
		vueApp.component(name, component);
		vueApp.component(`Lazy${name}`, defineAsyncComponent(() => Promise.resolve(component)));
	}
	if (isServer) {
		// Nuxt en développement recueille les avertissements de Vue du rendu serveur dans le journal de la page.
		vueApp.config.warnHandler = (message, _instance, trace) => {
			nuxtApp.logs.push({ date: new Date(), args: [`[Vue warn]: ${message}`, trace.trim()], type: 'warn', level: 1, tag: '', filename: '', stack: [] });
		};
		vueApp.use(options.ssrContext!.head);
	} else {
		installClientHead(nuxtApp, manifest.appHead ?? {});
	}
	await installRouter(nuxtApp, manifest, globalMiddleware, options);
	// entry.js : les plugins sont posés, `app:created` rejoue la navigation initiale (middlewares compris).
	try {
		await nuxtApp.hooks.callHook('app:created', vueApp);
	} catch (error) {
		await nuxtApp.hooks.callHook('app:error', error);
		nuxtApp.payload.error ||= createError(error);
	}
	return nuxtApp;
}

/** Plugin du routeur de pages (pages/runtime/plugins/router.js), sans transitions ni îlots. */
async function installRouter(nuxtApp: NuxtApp, manifest: AppManifest, globalMiddleware: RouteMiddleware[], options: CreateOptions): Promise<void> {
	const router = nuxtApp.router;
	const routerBase = options.runtimeConfig.app?.baseURL ?? '/';
	nuxtApp.vueApp.use(router);
	const initialURL = isServer ? nuxtApp.ssrContext?.url ?? options.url ?? '/' : createCurrentLocation(routerBase, window.location, nuxtApp.payload.path);
	// Les plugins de Nuxt tournent avec l'instance disponible (callWithNuxt).
	const error = runWithContext(nuxtApp, useError);
	router.afterEach(async (to, _from, failure) => {
		delete nuxtApp._processingMiddleware;
		if (isServer) delete nuxtApp._middlewareTo;
		if (!isServer && !nuxtApp.isHydrating && error.value) await runWithContext(nuxtApp, clearError);
		if (isServer && (failure as { type?: number } | undefined)?.type === 4) return;
		if (isServer && to.redirectedFrom && to.fullPath !== initialURL) await runWithContext(nuxtApp, () => navigateTo(to.fullPath || '/'));
	});
	try {
		if (isServer) await router.push(initialURL);
		await router.isReady();
	} catch (err) {
		await runWithContext(nuxtApp, () => showError(err));
	}
	const resolvedInitialRoute: Record<string, any> = !isServer && initialURL !== router.currentRoute.value.fullPath ? router.resolve(initialURL) : router.currentRoute.value;
	const prePluginRoutePath = !isServer ? router.currentRoute.value.fullPath : '';
	if (!manifest.pages) return;
	const initialLayout = (nuxtApp.payload.state as Record<string, any>)._layout;
	router.beforeEach(async (to, from) => {
		to.meta = reactive(to.meta);
		if (nuxtApp.isHydrating && initialLayout && !isReadonly(to.meta.layout)) to.meta.layout = initialLayout;
		nuxtApp._processingMiddleware = true;
		if (isServer) nuxtApp._middlewareTo = to;
		const middlewareEntries = new Set<string | RouteMiddleware>([...globalMiddleware, ...nuxtApp._middleware.global]);
		for (const component of to.matched) {
			const componentMiddleware = component.meta.middleware as string | RouteMiddleware | (string | RouteMiddleware)[] | undefined;
			if (!componentMiddleware) continue;
			for (const entry of Array.isArray(componentMiddleware) ? componentMiddleware : [componentMiddleware]) middlewareEntries.add(entry);
		}
		for (const entry of middlewareEntries) {
			const middleware = typeof entry === 'string' ? nuxtApp._middleware.named[entry] || (await manifest.middleware?.named[entry]?.().then((r) => r.default || r)) : entry;
			if (!middleware) {
				const valid = Object.keys(manifest.middleware?.named ?? {});
				throw new Error(`[NUXT_E2004] Unknown route middleware: '${String(entry)}'. Valid middleware: ${valid.map((name) => `'${name}'`).join(', ')}.`);
			}
			try {
				nuxtApp._processingMiddleware = typeof entry === 'string' ? entry : true;
				const result = await runWithContext(nuxtApp, () => (middleware as RouteMiddleware)(to, from));
				if (isServer || (!nuxtApp.payload.serverRendered && nuxtApp.isHydrating)) {
					if (result === false || result instanceof Error) {
						const failure = result || createError({ status: 404, statusText: `Page Not Found: ${initialURL}` });
						await runWithContext(nuxtApp, () => showError(failure));
						return false;
					}
				}
				if (result === true) continue;
				if (result === false) return result;
				if (result) {
					if (isNuxtError(result) && result.fatal) await runWithContext(nuxtApp, () => showError(result));
					return result as never;
				}
			} catch (err) {
				const failure = createError(err);
				if (failure.fatal) await runWithContext(nuxtApp, () => showError(failure));
				return failure as never;
			}
		}
	});
	router.onError(() => {
		delete nuxtApp._processingMiddleware;
		if (isServer) delete nuxtApp._middlewareTo;
	});
	router.afterEach((to) => {
		if (to.matched.length === 0 && !error.value) {
			return runWithContext(nuxtApp, () => showError(createError({ status: 404, fatal: false, statusText: `Page not found: ${to.fullPath}`, data: { path: to.fullPath } })));
		}
	});
	nuxtApp.hooks.hookOnce('app:created', async () => {
		try {
			if ('name' in resolvedInitialRoute) resolvedInitialRoute.name = undefined;
			if (!isServer && router.currentRoute.value.fullPath !== prePluginRoutePath) {
				// Une navigation a déjà eu lieu pendant les plugins : rien à rejouer.
			} else {
				await router.replace({ ...resolvedInitialRoute, force: true } as never);
			}
		} catch (err) {
			await runWithContext(nuxtApp, () => showError(err));
		}
	});
}

function createCurrentLocation(base: string, location: Location, renderedPath?: string): string {
	const { pathname, search, hash } = location;
	const displayedPath = withoutBase(pathname, base);
	const path = !renderedPath || isSamePath(displayedPath, renderedPath) ? displayedPath : renderedPath;
	return path + (path.includes('?') ? '' : search) + hash;
}

/** install-client-head.js : la tête du document est mise à jour une fois l'hydratation terminée. */
function installClientHead(nuxtApp: NuxtApp, appHead: Record<string, any>): void {
	const head = createClientHead(UNHEAD_OPTIONS as never);
	head.push(appHead);
	nuxtApp.vueApp.use(head);
	let pauseDOMUpdates = true;
	const syncHead = () => {
		pauseDOMUpdates = false;
		head.render();
	};
	head.hooks?.hook('dom:beforeRender', (context: { shouldRender: boolean }) => {
		context.shouldRender = !pauseDOMUpdates;
	});
	nuxtApp.hooks.hook('app:error', syncHead);
	nuxtApp.hooks.hook('app:suspense:resolve', syncHead);
}

/**
 * Propre à l'aperçu de la plateforme (pas à Nuxt) : les avertissements et erreurs de la page (erreurs
 * d'hydratation, [Vue warn], exceptions) sont aussi envoyés au relais du bac à sable, qui les montre dans
 * la console du playground : l'apprenant n'a pas les outils de développement de l'iframe sous la main.
 */
function relayConsoleToPreview(): void {
	if (window.parent === window) return;
	// Le texte seulement : les objets passés en plus (props des composants de la trace de Vue) noieraient le message.
	const format = (arg: unknown): string | undefined => {
		if (typeof arg === 'string') return arg;
		if (typeof arg === 'number' || typeof arg === 'boolean') return String(arg);
		if (arg instanceof Error) return arg.stack ?? `${arg.name}: ${arg.message}`;
		if (typeof Element !== 'undefined' && arg instanceof Element) return `<${arg.tagName.toLowerCase()}>`;
		return undefined;
	};
	const send = (level: 'warn' | 'error', args: unknown[]) => {
		try {
			const message = args.map(format).filter((part) => part !== undefined).join(' ').replace(/ +>/g, '>');
			window.parent.postMessage({ type: 'nuxt-sim:console', level, message: message.slice(0, 4000) }, location.origin);
		} catch {
			// Relais absent ou d'une autre origine : la console du navigateur suffit.
		}
	};
	for (const level of ['warn', 'error'] as const) {
		const original = console[level].bind(console);
		console[level] = (...args: unknown[]) => {
			original(...args);
			send(level, args);
		};
	}
	window.addEventListener('error', (event) => send('error', [event.error ?? event.message]));
	window.addEventListener('unhandledrejection', (event) => send('error', [event.reason]));
}

/** Nuxt en développement recopie dans la console du navigateur ce que le rendu serveur a signalé. */
function printServerLogs(): void {
	const element = document.querySelector('script[data-nuxt-logs]');
	if (!element?.textContent) return;
	for (const log of parse(element.textContent) as { type: string; args: unknown[] }[]) {
		(log.type === 'error' ? console.error : console.warn)('[SSR]', ...log.args);
	}
}

/** Démarrage dans l'aperçu : relit la configuration et la charge utile servies, puis hydrate #__nuxt. */
export async function startClient(manifest: AppManifest): Promise<void> {
	relayConsoleToPreview();
	printServerLogs();
	const nuxtWindow = window as unknown as { __NUXT__?: { config?: Record<string, any> } };
	const dataElement = document.getElementById('__NUXT_DATA__');
	const payload = dataElement?.textContent ? (parse(dataElement.textContent, payloadRevivers) as NuxtPayload) : undefined;
	const nuxtApp = await createNuxtApp(manifest, { runtimeConfig: nuxtWindow.__NUXT__?.config ?? {}, payload });
	nuxtApp.vueApp.mount('#__nuxt');
}
