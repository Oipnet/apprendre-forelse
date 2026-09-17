/**
 * Création de l'application Vue d'une requête (serveur) ou de la page (navigateur), à la manière de
 * nuxt/dist/app/entry et du plugin de routeur de pages. Une application par requête côté serveur :
 * c'est ce qui rend visibles les fuites d'état entre visiteurs (chapitre 3 du parcours).
 */
import { parse } from 'devalue';
import { createHooks } from 'hookable';
import {
	Suspense, createSSRApp, defineAsyncComponent, defineComponent, h, isReactive, isRef, isShallow, provide, reactive, ref, shallowReactive, shallowRef, toRaw,
	type Component,
} from 'vue';
import { createMemoryHistory, createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';
import { ClientOnly, NuxtLayout, NuxtLink, NuxtPage } from './components.ts';
import { createError, isNuxtError } from './data.ts';
import { PageRouteSymbol, isServer, runWithContext, useNuxtApp, useRoute, type NuxtApp, type NuxtPayload } from './nuxt.ts';

type Loader = () => Promise<{ default: Component }>;

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
}

export interface CreateOptions {
	/** Côté serveur : l'URL demandée (chemin + query). */
	url?: string;
	runtimeConfig: Record<string, any>;
	payload?: NuxtPayload;
	/** Côté serveur : l'événement de la requête et le `$fetch` de Nitro (voir useRequestFetch). */
	ssrContext?: NuxtApp['ssrContext'];
}

/** `app.vue` par défaut de Nuxt quand le projet n'en a pas (pages/runtime/app.vue, sans NuxtRouteAnnouncer). */
const DefaultApp = defineComponent({
	name: 'NuxtApp',
	setup: () => () => h(NuxtLayout, null, { default: () => h(NuxtPage) }),
});

function createNuxtRoot(appComponent: Component) {
	return defineComponent({
		name: 'NuxtRoot',
		setup() {
			const nuxtApp = useNuxtApp();
			provide(PageRouteSymbol, useRoute());
			return () => h(Suspense, { onResolve: () => { nuxtApp.isHydrating = false; } }, { default: () => h(appComponent) });
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
	const [appModule, layoutEntries, componentEntries, appConfigModule] = await Promise.all([
		manifest.app?.(),
		Promise.all(Object.entries(manifest.layouts).map(async ([name, load]) => [name, (await load()).default] as const)),
		Promise.all(Object.entries(manifest.components).map(async ([name, load]) => [name, (await load()).default] as const)),
		manifest.appConfig?.(),
	]);
	const vueApp = createSSRApp(createNuxtRoot(appModule?.default ?? DefaultApp));
	const router = createRouter({
		history: isServer ? createMemoryHistory(options.runtimeConfig.app?.baseURL) : createWebHistory(options.runtimeConfig.app?.baseURL),
		routes: manifest.pages ? toRouteRecords(manifest.routes) : [{ path: '/:pathMatch(.*)*', component: { render: () => null } }],
	});
	const hooks = createHooks<Record<string, any>>();
	const nuxtApp: NuxtApp = {
		vueApp,
		router,
		isServer,
		isHydrating: !isServer,
		layouts: Object.fromEntries(layoutEntries),
		payload: options.payload ?? createPayload(),
		runtimeConfig: options.runtimeConfig,
		logs: [],
		_state: {},
		appConfig: appConfigModule?.default ?? {},
		hooks,
		hook: hooks.hook,
		callHook: hooks.callHook,
		static: { data: {} },
		_asyncData: shallowReactive({}),
		_asyncDataPromises: {},
		ssrContext: options.ssrContext,
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
	}
	vueApp.use(router);
	if (isServer && options.url) {
		await router.push(options.url);
		await router.isReady();
	}
	return nuxtApp;
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
	await nuxtApp.router.isReady();
	nuxtApp.vueApp.mount('#__nuxt');
}
