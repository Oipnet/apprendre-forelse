/**
 * Composants de Nuxt, portés depuis nuxt/dist (app/components et pages/runtime) en gardant la structure
 * de rendu qui détermine le HTML : RouterView → Suspense → RouteProvider pour les pages, Suspense →
 * NuxtLayoutProvider → LayoutLoader pour les layouts. Laissés de côté pour l'instant : transitions,
 * keep-alive, préchargement des liens, îlots, diagnostics de développement.
 */
import {
	Fragment, Suspense, cloneVNode, computed, createCommentVNode, createElementBlock, defineComponent, getCurrentInstance, h, inject, isVNode,
	mergeProps, onMounted, provide, ref, resolveComponent, shallowReactive, shallowRef, unref, type PropType, type VNode,
} from 'vue';
import { RouterView, useRoute as useRouterRoute, type RouteLocationNormalizedLoaded, type RouteLocationRaw } from 'vue-router';
import { LayoutMetaSymbol, LayoutSymbol, PageRouteSymbol, devWarning, isServer, useNuxtApp, useRoute, useRouter } from './nuxt.ts';

// --- NuxtPage (pages/runtime/page.ts) ---

const ROUTE_KEY_PARENTHESES_RE = /(:\w+)\([^)]+\)/g;
const ROUTE_KEY_SYMBOLS_RE = /(:\w+)[?+*]/g;
const ROUTE_KEY_NORMAL_RE = /:\w+/g;

interface RouteProps {
	Component: VNode;
	route: RouteLocationNormalizedLoaded;
}

function interpolatePath(route: RouteLocationNormalizedLoaded, match: { path: string }): string {
	return match.path
		.replace(ROUTE_KEY_PARENTHESES_RE, '$1')
		.replace(ROUTE_KEY_SYMBOLS_RE, '$1')
		.replace(ROUTE_KEY_NORMAL_RE, (param) => route.params[param.slice(1)]?.toString() || '');
}

function generateRouteKey(routeProps: RouteProps, override?: string | ((route: RouteLocationNormalizedLoaded) => string) | null): string | undefined {
	const matchedRoute = routeProps.route.matched.find((m) => m.components?.default === routeProps.Component.type);
	const source = override ?? (matchedRoute?.meta.key as string | undefined) ?? (matchedRoute && interpolatePath(routeProps.route, matchedRoute));
	return typeof source === 'function' ? source(routeProps.route) : source;
}

function markStableSlot(fn: (routeProps: RouteProps) => VNode | undefined) {
	const wrapped = (routeProps: RouteProps) => {
		const result = fn(routeProps);
		if (Array.isArray(result)) return result;
		if (result == null || !isVNode(result)) return [createCommentVNode()];
		return [result];
	};
	(wrapped as { _n?: boolean })._n = true;
	return wrapped;
}

function normalizeSlot(slot: (data: RouteProps) => VNode[], data: RouteProps): VNode {
	const content = slot(data);
	return content.length === 1 ? h(content[0]) : h(Fragment, undefined, content);
}

export const RouteProvider = defineComponent({
	name: 'RouteProvider',
	props: {
		route: { type: Object as PropType<RouteLocationNormalizedLoaded>, required: true },
		vnode: Object as PropType<VNode>,
		vnodeRef: Object,
		renderKey: String,
		trackRootNodes: Boolean,
	},
	setup(props) {
		const previousKey = props.renderKey;
		const previousRoute = props.route;
		const route = {} as RouteLocationNormalizedLoaded;
		for (const key in props.route) {
			Object.defineProperty(route, key, {
				get: () => (previousKey === props.renderKey ? props.route[key as keyof RouteLocationNormalizedLoaded] : previousRoute[key as keyof RouteLocationNormalizedLoaded]),
				enumerable: true,
			});
		}
		provide(PageRouteSymbol, shallowReactive(route));
		return () => (props.vnode ? h(props.vnode, { ref: props.vnodeRef as never }) : props.vnode);
	},
});

export const NuxtPage = defineComponent({
	name: 'NuxtPage',
	inheritAttrs: false,
	props: {
		name: { type: String },
		route: { type: Object as PropType<RouteLocationNormalizedLoaded> },
		pageKey: { type: [Function, String] as PropType<string | ((route: RouteLocationNormalizedLoaded) => string)>, default: null },
	},
	setup(props, { attrs, slots, expose }) {
		const pageRef = ref();
		expose({ pageRef });
		return () => h(RouterView, { name: props.name, route: props.route, ...attrs }, {
			default: markStableSlot(isServer
				? (routeProps) => h(Suspense, { suspensible: true }, {
					default: () => h(RouteProvider, {
						vnode: slots.default ? normalizeSlot(slots.default as never, routeProps) : routeProps.Component,
						route: routeProps.route,
						vnodeRef: pageRef,
					}),
				})
				: (routeProps) => {
					if (!routeProps.Component) return undefined;
					const key = generateRouteKey(routeProps, props.pageKey);
					return h(Suspense, { suspensible: true }, {
						default: () => h(RouteProvider, {
							key: key || undefined,
							vnode: slots.default ? normalizeSlot(slots.default as never, routeProps) : routeProps.Component,
							route: routeProps.route,
							renderKey: key || undefined,
							vnodeRef: pageRef,
						}),
					});
				}),
		});
	},
});

// --- NuxtLayout (app/components/nuxt-layout.ts) ---

const LayoutLoader = defineComponent({
	name: 'LayoutLoader',
	inheritAttrs: false,
	props: { name: String, layoutProps: Object },
	setup(props, context) {
		const nuxtApp = useNuxtApp();
		return () => h(nuxtApp.layouts[props.name!], props.layoutProps, context.slots);
	},
});

function resolveLayoutName(route: RouteLocationNormalizedLoaded | undefined, name?: unknown): string | false {
	return (unref(name) as string | false | null | undefined) ?? (route?.meta.layout as string | false | undefined) ?? 'default';
}

const NuxtLayoutProvider = defineComponent({
	name: 'NuxtLayoutProvider',
	inheritAttrs: false,
	props: {
		name: { type: [String, Boolean] },
		layoutProps: { type: Object },
		shouldProvide: { type: Boolean },
	},
	setup(props, context) {
		const nuxtApp = useNuxtApp();
		const name = props.name;
		if (props.shouldProvide) {
			provide(LayoutMetaSymbol, { isCurrent: (route) => name === false || name === resolveLayoutName(route) });
		}
		return () => {
			if (!name || (typeof name === 'string' && !(name in nuxtApp.layouts))) {
				return context.slots.default?.();
			}
			return h(LayoutLoader, { key: name as string, layoutProps: props.layoutProps, name: name as string }, context.slots);
		};
	},
});

export const NuxtLayout = defineComponent({
	name: 'NuxtLayout',
	inheritAttrs: false,
	props: {
		name: { type: [String, Boolean, Object] as PropType<string | false | { value: string }>, default: null },
		fallback: { type: [String, Object] as PropType<string | { value: string }>, default: null },
	},
	setup(props, context) {
		const nuxtApp = useNuxtApp();
		const injectedRoute = inject(PageRouteSymbol, undefined);
		const route = !injectedRoute || injectedRoute === useRoute() ? useRouterRoute() : injectedRoute;
		const layout = computed(() => {
			let name = resolveLayoutName(route, props.name);
			if (name && !(name in nuxtApp.layouts)) {
				if (name !== 'default') {
					const available = Object.keys(nuxtApp.layouts).join(', ') || 'none';
					devWarning(nuxtApp, 'NUXT_E4001', `Invalid layout \`${name}\` selected. Available layouts: ${available}.`, `Create a \`layouts/${name}.vue\` file, or use one of the available layouts.`);
				}
				if (props.fallback) name = unref(props.fallback as never) as string;
			}
			return name;
		});
		provide(LayoutSymbol, layout);
		return () => h(Suspense, { suspensible: true }, {
			default: () => h(NuxtLayoutProvider, {
				layoutProps: mergeProps(context.attrs, (route.meta.layoutProps as Record<string, unknown>) ?? {}),
				key: layout.value || undefined,
				name: layout.value,
				shouldProvide: !props.name,
			}, context.slots),
		});
	},
});

// --- NuxtLink (app/components/nuxt-link.ts), sans préchargement ---

function hasProtocol(input: string): boolean {
	return /^[\s\w\0+.-]{2,}:([/\\]{1,2})/.test(input) || input.startsWith('//') || /^[\s\w\0+.-]{2,}:/.test(input);
}

export const NuxtLink = defineComponent({
	name: 'NuxtLink',
	props: {
		to: { type: [String, Object] as PropType<RouteLocationRaw>, default: undefined, required: false },
		href: { type: [String, Object] as PropType<RouteLocationRaw>, default: undefined, required: false },
		target: { type: String, default: undefined, required: false },
		rel: { type: String, default: undefined, required: false },
		noRel: { type: Boolean, default: undefined, required: false },
		external: { type: Boolean, default: undefined, required: false },
		replace: { type: Boolean, default: undefined, required: false },
		activeClass: { type: String, default: undefined, required: false },
		exactActiveClass: { type: String, default: undefined, required: false },
		ariaCurrentValue: { type: String, default: undefined, required: false },
		custom: { type: Boolean, default: undefined, required: false },
	},
	setup(props, { slots }) {
		const router = useRouter();
		const path = () => unref(props.to) || unref(props.href) || '';
		const isAbsoluteUrl = computed(() => {
			const value = path();
			return typeof value === 'string' && hasProtocol(value);
		});
		const isExternal = computed(() => {
			if (props.external) return true;
			const value = path();
			if (typeof value === 'object') return false;
			return value === '' || isAbsoluteUrl.value;
		});
		const hasTarget = computed(() => !!props.target && props.target !== '_self');
		const href = computed(() => (isExternal.value ? (path() as string) : router.resolve(path()).href));
		return () => {
			const target = props.target || null;
			const ownRel = props.noRel ? '' : props.rel;
			const rel = (ownRel !== undefined ? ownRel : isAbsoluteUrl.value || hasTarget.value ? 'noopener noreferrer' : '') || null;
			if (!isExternal.value && !hasTarget.value) {
				return h(resolveComponent('RouterLink'), {
					to: path(),
					activeClass: props.activeClass,
					exactActiveClass: props.exactActiveClass,
					replace: props.replace,
					ariaCurrentValue: props.ariaCurrentValue,
					custom: props.custom,
					rel: props.rel || undefined,
				}, slots.default);
			}
			return h('a', {
				href: href.value || null,
				rel: rel || null,
				target,
				onClick: async (event: MouseEvent) => {
					if (isExternal.value || hasTarget.value) return;
					event.preventDefault();
					return props.replace ? router.replace(href.value) : router.push(href.value);
				},
			}, slots.default?.());
		};
	},
});

// --- ClientOnly (app/components/client-only.ts) ---

const clientOnlySymbol = Symbol.for('nuxt:client-only');
const VALID_TAG_RE = /^[a-z][a-z0-9-]*$/i;

/** Rien côté serveur (sinon le repli) ; le contenu n'apparaît qu'une fois le composant monté dans le navigateur. */
export const ClientOnly = defineComponent({
	name: 'ClientOnly',
	inheritAttrs: false,
	props: ['fallback', 'placeholder', 'placeholderTag', 'fallbackTag'],
	setup(props, { slots, attrs }) {
		const mounted = shallowRef(false);
		onMounted(() => {
			mounted.value = true;
		});
		const vm = getCurrentInstance() as { _nuxtClientOnly?: boolean } | null;
		if (vm) vm._nuxtClientOnly = true;
		provide(clientOnlySymbol, true);
		return () => {
			if (mounted.value) {
				const vnodes = slots.default?.();
				if (vnodes && vnodes.length === 1) return [cloneVNode(vnodes[0], attrs)];
				return vnodes;
			}
			const slot = slots.fallback || slots.placeholder;
			if (slot) return h(slot as never);
			const fallbackStr = props.fallback || props.placeholder || '';
			const tag = props.fallbackTag || props.placeholderTag;
			return createElementBlock(tag && VALID_TAG_RE.test(tag) ? tag : 'span', attrs, fallbackStr);
		};
	},
});
