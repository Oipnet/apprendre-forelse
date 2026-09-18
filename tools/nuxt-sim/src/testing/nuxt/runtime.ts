/**
 * Runtime de l'environnement de test `nuxt`, empaqueté avec Vue, vue-router et @vue/test-utils (voir
 * src/node/test-runtime-bundle.ts) : chaque fichier de test en évalue une copie neuve, une fois le DOM
 * installé. Vue lit `document` au chargement, et Vitest donne à chaque fichier ses propres modules.
 *
 * Portages de @nuxt/test-utils 4.3.2 : runtime/nuxt-root, runtime/shared/nuxt (setupNuxt),
 * runtime/shared/vue-wrapper-plugin, runtime-utils (mountSuspended). Les fonctions de Nuxt passent par
 * `imports`, la table des auto-imports du fichier : `mockNuxtImport` y remplace une fonction, comme
 * `vi.mock` la remplace pour tous ceux qui l'importent (la racine de test comprise).
 */
import { config as testUtilsConfig, mount } from '@vue/test-utils';
import * as vueTestUtils from '@vue/test-utils';
import * as vue from 'vue';
import { Suspense, defineComponent, effectScope, getCurrentInstance, h, nextTick, onErrorCaptured, provide, reactive, type Component } from 'vue';
import * as vueRouter from 'vue-router';
import { createNuxtApp, createPayload, type AppManifest } from '../../app/runtime/app.ts';
import * as composables from '../../app/runtime/composables.ts';
import { PageRouteSymbol, type NuxtApp } from '../../app/runtime/nuxt.ts';

export { composables, vue, vueRouter, vueTestUtils };

type Imports = Record<string, any>;

/** Quand l'application n'a plus d'étape d'hydratation en attente, `isHydrating` passe à faux (nuxt.js). */
function deferHydration(nuxtApp: NuxtApp & { _hydratingCount?: number }): () => unknown {
	if (!nuxtApp.isHydrating) return () => {};
	nuxtApp._hydratingCount = (nuxtApp._hydratingCount ?? 0) + 1;
	let called = false;
	return () => {
		if (called) return;
		called = true;
		nuxtApp._hydratingCount! -= 1;
		if (nuxtApp._hydratingCount === 0) {
			nuxtApp.isHydrating = false;
			return nuxtApp.callHook('app:suspense:resolve');
		}
	};
}

/**
 * runtime/nuxt-root : la racine qui remplace NuxtRoot dans l'application de test (ni page d'erreur, ni
 * app.vue). Elle est chargée avant les simulations du fichier : ses fonctions de Nuxt sont les vraies.
 */
function createTestRoot(): Component {
	return defineComponent({
		setup(_props, { slots }) {
			const nuxtApp = composables.useNuxtApp();
			provide(PageRouteSymbol, composables.useRoute());
			const done = deferHydration(nuxtApp);
			onErrorCaptured((err, target, info) => {
				nuxtApp.hooks.callHook('vue:error', err, target, info)?.catch((hookError: unknown) => console.error('[nuxt] Error in `vue:error` hook', hookError));
				if (composables.isNuxtError(err) && ((err as any).fatal || (err as any).unhandled)) return false;
			});
			return () => h(Suspense, { onResolve: done }, slots.default?.());
		},
	});
}

/**
 * `setup` de app/components/nuxt-root.vue (côté navigateur), que mountSuspended appelle pour ses effets :
 * route injectée, hooks `vue:setup`, erreurs fatales montrées. Il rend ses liaisons, pas un rendu.
 */
function nuxtRootSetup(imports: Imports): Record<string, unknown> {
	const nuxtApp = imports.useNuxtApp() as NuxtApp;
	const onResolve = deferHydration(nuxtApp);
	if (nuxtApp.isHydrating) {
		const removeErrorHook = nuxtApp.hooks.hookOnce('app:error', onResolve);
		const removeGuard = imports.useRouter().beforeEach(() => {
			removeErrorHook();
			removeGuard();
		});
	}
	provide(PageRouteSymbol, imports.useRoute());
	nuxtApp.hooks.callHookWith((hooks: (() => unknown)[]) => hooks.map((hook) => hook()), 'vue:setup', []);
	const error = imports.useError();
	onErrorCaptured((err, target, info) => {
		nuxtApp.hooks.callHook('vue:error', err, target, info)?.catch((hookError: unknown) => console.error('[nuxt] Error in `vue:error` hook', hookError));
		if (imports.isNuxtError(err) && ((err as any).fatal || (err as any).unhandled)) {
			nuxtApp.vueApp.runWithContext(() => imports.showError(err));
			const errorHandler = nuxtApp.vueApp.config.errorHandler as ((...args: unknown[]) => void) & { __nuxt_default?: boolean } | undefined;
			if (errorHandler && !errorHandler.__nuxt_default) {
				try {
					errorHandler(err, target, info);
				} catch (handlerError) {
					console.error('[nuxt] Error in `app.config.errorHandler`', handlerError);
				}
			}
			return false;
		}
	});
	return { nuxtApp, onResolve, error };
}

// --- runtime/shared/vue-wrapper-plugin -------------------------------------------------------

const PLUGIN_NAME = 'nuxt-test-utils';

interface WrapperPluginOptions {
	_name: string;
	_instances: WeakRef<any>[];
	readonly instances: any[];
	addInstance(instance: any): void;
	hasNuxtPage(): boolean;
}

function getVueWrapperPlugin(): WrapperPluginOptions {
	const installed = (testUtilsConfig.plugins.VueWrapper as any).installedPlugins.find(({ options }: any) => options?._name === PLUGIN_NAME);
	if (installed) return installed.options;
	const options: WrapperPluginOptions = {
		_name: PLUGIN_NAME,
		_instances: [],
		get instances() {
			const instances: any[] = [];
			options._instances = options._instances.filter((ref) => {
				const instance = ref.deref();
				if (!instance) return false;
				instances.push(instance);
				return true;
			});
			return instances;
		},
		addInstance(instance) {
			if (options.instances.includes(instance)) return;
			options._instances.push(new WeakRef(instance));
		},
		hasNuxtPage() {
			return options.instances.some((v) => v.exists() && v.findComponent({ name: 'NuxtPage' }).exists());
		},
	};
	testUtilsConfig.plugins.VueWrapper.install((instance: any, pluginOptions: any) => {
		pluginOptions.addInstance(instance);
		return {};
	}, options as never);
	return options;
}

/** runtime/shared/nuxt : l'application du fichier de test, montée sur #__nuxt avant le premier test. */
export async function setupNuxt(manifest: AppManifest): Promise<void> {
	const win = globalThis as unknown as { __NUXT__: { config: Record<string, any> } };
	const payload = Object.assign(createPayload(), { serverRendered: false });
	const nuxtApp = await createNuxtApp(manifest, { runtimeConfig: win.__NUXT__.config, payload, rootComponent: createTestRoot() });
	await nuxtApp.hooks.callHook('app:beforeMount', nuxtApp.vueApp);
	nuxtApp.vueApp.mount('#__nuxt');
	await nuxtApp.hooks.callHook('app:mounted', nuxtApp.vueApp);
	await nextTick();
	const sync = () => nuxtApp.callHook('page:finish');
	const { hasNuxtPage } = getVueWrapperPlugin();
	nuxtApp.router.afterEach(() => {
		if (hasNuxtPage()) return;
		return sync();
	});
	await sync();
}

// --- runtime-utils/components/RouterLink ------------------------------------------------------

function createRouterLink(imports: Imports): Component {
	return defineComponent({
		functional: true,
		props: { to: { type: [String, Object], required: true }, custom: Boolean, replace: Boolean, activeClass: String, exactActiveClass: String, ariaCurrentValue: String },
		setup: (props: any, { slots }) => {
			const linkComponent = (imports.useNuxtApp() as NuxtApp).vueApp._context.components.RouterLink as any;
			const useLink = linkComponent && typeof linkComponent === 'object' && typeof linkComponent.useLink === 'function' ? linkComponent.useLink.bind(linkComponent) : undefined;
			if (!useLink) {
				const navigate = () => {};
				const router = imports.useRouter();
				return () => {
					const route = router.resolve(props.to);
					return props.custom
						? slots.default?.({ href: route.href, navigate, route })
						: h('a', { href: route.href, onClick: (e: Event) => e.preventDefault() }, slots);
				};
			}
			const link = useLink(props);
			return () => {
				const route = link.route.value;
				const href = link.href.value;
				return props.custom
					? slots.default?.({ href, navigate: link.navigate, route, isActive: link.isActive.value, isExactActive: link.isExactActive.value })
					: h('a', { href, onClick: (e: Event) => { e.preventDefault(); return link.navigate(e); } }, slots);
			};
		},
	});
}

// --- runtime-utils/utils/suspended ------------------------------------------------------------

type Cleanup = () => void;

function cleanupAll(): void {
	const win = globalThis as unknown as { __cleanup?: Cleanup[] };
	for (const fn of (win.__cleanup || []).splice(0)) fn();
}

function addCleanup(fn: Cleanup): void {
	const win = globalThis as unknown as { __cleanup?: Cleanup[] };
	win.__cleanup ||= [];
	win.__cleanup.push(fn);
}

function runEffectScope<T>(fn: () => T, register: (cleanup: Cleanup) => void): T | undefined {
	const scope = effectScope();
	register(() => scope.stop());
	return scope.run(fn);
}

interface MountOptions extends Record<string, any> {
	route?: string | Record<string, unknown> | false;
	scoped?: boolean;
	spy?: boolean;
}

/** `mountSuspended` de ce fichier de test : ses fonctions de Nuxt (éventuellement simulées) et son `vi`. */
export function createMountSuspended(imports: Imports, vi: { mockObject?: (value: any, options: any) => any }) {
	const RouterLink = createRouterLink(imports);
	const suspendedHelperName = 'MountSuspendedHelper';
	const clonedComponentName = 'MountSuspendedComponent';

	function wrapperSuspended(component: any, options: MountOptions): Promise<{ wrapper: any; setProps: (props: Record<string, unknown>) => void }> {
		const nuxtApp = imports.useNuxtApp() as NuxtApp;
		const vueApp = nuxtApp.vueApp as any;
		const ownCleanups: Cleanup[] = [];
		const registerCleanup = (fn: Cleanup) => {
			ownCleanups.push(fn);
			addCleanup(fn);
		};
		const { props = {}, attrs = {} } = options;
		const { route = '/', scoped = false, spy = false, ...wrapperFnOptions } = options;
		const { render: _componentRender, setup: componentSetup, ...componentRest } = component;
		let wrappedInstance: any = null;
		let setupContext: any;
		let setupState: unknown = {};
		const setProps = reactive<Record<string, unknown>>({});

		function patchInstanceAppContext() {
			const app = getCurrentInstance()?.appContext.app as any;
			if (!app) return;
			for (const [key, value] of Object.entries(vueApp)) {
				if (key in app) continue;
				app[key] = value;
			}
		}

		const ClonedComponent = {
			components: {},
			...component,
			name: clonedComponentName,
			async setup(setupProps: unknown, instanceContext: any) {
				const currentInstance = getCurrentInstance() as any;
				if (currentInstance) {
					currentInstance.emit = (event: string, ...args: unknown[]) => {
						setupContext.emit(event, ...args);
					};
				}
				if (!componentSetup) return;
				let result = scoped ? await runEffectScope(() => componentSetup(setupProps, setupContext), registerCleanup) : await componentSetup(setupProps, setupContext);
				if (wrappedInstance?.exposed) instanceContext.expose(wrappedInstance.exposed);
				if (result && typeof result === 'object') {
					if (spy) {
						if (!vi.mockObject) throw new Error('mountSuspended({ spy: true }) : vi.mockObject n\'est pas disponible dans le simulateur.');
						result = vi.mockObject(result, { spy: true });
					}
					setupState = result;
				} else {
					setupState = {};
				}
				return result;
			},
		};

		const SuspendedHelper = {
			name: suspendedHelperName,
			render: () => '',
			async setup() {
				if (route) await imports.useRouter().replace(route);
				return () => h(ClonedComponent, { ...props, ...setProps, ...attrs }, setupContext.slots);
			},
		};

		return new Promise((resolve, reject) => {
			let isMountSettled = false;
			const wrapper: any = mount({
				inheritAttrs: false,
				__cssModules: componentRest.__cssModules,
				setup: (_props: unknown, ctx: any) => {
					patchInstanceAppContext();
					wrappedInstance = getCurrentInstance();
					setupContext = ctx;
					const nuxtRootSetupResult = runEffectScope(() => nuxtRootSetup(imports), registerCleanup);
					onErrorCaptured((error, ...args) => {
						if (isMountSettled) return;
						isMountSettled = true;
						try {
							wrappedInstance?.appContext.config.errorHandler?.(error, ...args);
							reject(error);
						} catch (handlerError) {
							reject(handlerError);
						}
						return false;
					});
					return nuxtRootSetupResult;
				},
				render: () => h(Suspense, {
					onResolve: () => nextTick().then(() => {
						if (isMountSettled) return;
						isMountSettled = true;
						wrapper.setupState = setupState;
						resolve({ wrapper, setProps: (newProps) => Object.assign(setProps, newProps) });
					}),
				}, { default: () => h(SuspendedHelper) }),
			} as never, {
				...wrapperFnOptions,
				global: mergeComponentMountingGlobalOptions(wrapperFnOptions.global, {
					config: { globalProperties: makeAllPropertiesEnumerable(vueApp.config.globalProperties) },
					directives: vueApp._context.directives,
					provide: vueApp._context.provides,
					stubs: { Suspense: false, [suspendedHelperName]: false, [clonedComponentName]: false },
					components: { ...vueApp._context.components, RouterLink },
				}),
			} as never);
		});
	}

	return async function mountSuspended(component: any, options: MountOptions = {}) {
		cleanupAll();
		const { wrapper, setProps } = await wrapperSuspended(component, options);
		Object.assign(wrapper, { __setProps: setProps });
		return wrappedMountedWrapper(wrapper, wrapper.findComponent({ name: clonedComponentName }));
	};
}

function wrappedMountedWrapper(wrapper: any, component: any) {
	const wrapperProps = ['setProps', 'emitted', 'setupState', 'unmount'];
	return new Proxy(wrapper, {
		get: (_, prop, receiver) => {
			if (prop === 'getCurrentComponent') return getCurrentComponentPatchedProxy;
			const target = wrapperProps.includes(prop as string) ? wrapper : Reflect.has(component, prop) ? component : wrapper;
			const value = Reflect.get(target, prop, receiver);
			return typeof value === 'function' ? value.bind(target) : value;
		},
	});

	function getCurrentComponentPatchedProxy() {
		const currentComponent = component.getCurrentComponent();
		return new Proxy(currentComponent, {
			get: (target, prop, receiver) => {
				const value = Reflect.get(target, prop, receiver);
				if (prop === 'proxy' && value) {
					return new Proxy(value, {
						get(o, p, r) {
							if (!Reflect.has(currentComponent.props, p)) {
								const setupState = wrapper.setupState;
								if (setupState && typeof setupState === 'object' && Reflect.has(setupState, p)) return Reflect.get(setupState, p, r);
							}
							return Reflect.get(o, p, r);
						},
					});
				}
				return value;
			},
		});
	}
}

function mergeComponentMountingGlobalOptions(options: Record<string, any> = {}, defaults: Record<string, any> = {}) {
	const compilerOptions = { ...defaults.config?.compilerOptions, ...options.config?.compilerOptions };
	return {
		...options,
		mixins: [...(defaults.mixins || []), ...(options.mixins || [])],
		stubs: { ...defaults.stubs, ...(Array.isArray(options.stubs) ? Object.fromEntries(options.stubs.map((n: string) => [n, true])) : options.stubs) },
		plugins: [...(defaults.plugins || []), ...(options.plugins || [])],
		components: { ...defaults.components, ...options.components },
		provide: { ...defaults.provide, ...options.provide },
		mocks: { ...defaults.mocks, ...options.mocks },
		config: {
			...defaults.config,
			...options.config,
			...(Object.keys(compilerOptions).length ? { compilerOptions } : undefined),
			globalProperties: { ...defaults.config?.globalProperties, ...options.config?.globalProperties },
		},
		directives: { ...defaults.directives, ...options.directives },
	};
}

function makeAllPropertiesEnumerable(target: Record<string, unknown>) {
	return { ...target, ...Object.fromEntries(Object.getOwnPropertyNames(target).map((key) => [key, target[key]])) };
}
