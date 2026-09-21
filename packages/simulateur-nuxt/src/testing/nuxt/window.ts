/**
 * Fenêtre de l'environnement de test `nuxt` : portage de `setupWindow` (@nuxt/test-utils 4.3,
 * runtime/shared/environment et h3-v1) et de `registerEndpoint` (runtime-utils/mock).
 *
 * Dans cet environnement, l'application tourne comme dans un navigateur, sans le serveur de Nitro : un
 * `fetch` vers un chemin (« /api/… ») n'est servi que par les routes enregistrées avec `registerEndpoint`,
 * dans une petite application h3 ; les autres chemins reçoivent le 404 de h3. Une URL absolue part vers
 * le `fetch` d'origine.
 */
import { createFetch } from 'ofetch';
import { createError, isError } from '../../h3/error.ts';
import { H3Event, SimulatedRequest, SimulatedResponse } from '../../h3/event.ts';
import { handleHandlerResponse, setResponseStatus, splitCookiesString, type EventHandler } from '../../h3/utils.ts';

interface EndpointConfig {
	url: string;
	handler: EventHandler;
	method?: string;
	once?: boolean;
}

export interface EnvironmentWindow {
	__NUXT_VITEST_ENVIRONMENT__?: boolean;
	__NUXT__?: Record<string, any>;
	__registry?: Set<string>;
	__app?: RegistryApp;
	__cleanup?: (() => void)[];
	fetch: typeof fetch;
	$fetch?: unknown;
	document: Document;
	[key: string]: any;
}

/** L'application h3 des routes enregistrées (createApp de h3 1.x, réduite à ce que registerEndpoint utilise). */
export class RegistryApp {
	readonly endpoints: Record<string, EndpointConfig[]> = {};

	/** Gestionnaire unique de @nuxt/test-utils : la dernière route enregistrée pour ce chemin et cette méthode. */
	private find(path: string): EndpointConfig[] | undefined {
		const pathname = path.replace(/[?#].*$/, '');
		for (const [key, handlers] of Object.entries(this.endpoints)) {
			if ((key === path || key === pathname) && handlers?.length) return handlers;
		}
		return undefined;
	}

	async handle(url: string, init: RequestInit | undefined, registry: Set<string>): Promise<Response> {
		const headers: Record<string, string> = {};
		new Headers(init?.headers).forEach((value, name) => (headers[name] = value));
		headers.host ??= 'localhost';
		const body = typeof init?.body === 'string' ? new TextEncoder().encode(init.body) : init?.body instanceof Uint8Array ? init.body : undefined;
		const req = new SimulatedRequest(init?.method || 'GET', url, headers, body);
		req.originalUrl = url;
		const res = new SimulatedResponse();
		const event = new H3Event(req, res);
		try {
			const path = decodeURI(url);
			event._path = path;
			const handlers = this.find(path.replace(/^\/_/, ''));
			const latest = [...(handlers ?? [])].reverse().find((config) => (config.method ? event.method === config.method : true));
			const value = latest ? await latest.handler(event) : undefined;
			if (latest?.once) {
				handlers!.splice(handlers!.indexOf(latest), 1);
				if (handlers!.length === 0) registry.delete(latest.url);
			}
			if (value !== undefined) {
				await handleHandlerResponse(event, value);
			} else if (!event.handled) {
				throw createError({ statusCode: 404, statusMessage: `Cannot find any path matching ${path || '/'}.` });
			}
		} catch (thrown) {
			const error = createError(thrown as Error);
			if (!isError(thrown)) error.unhandled = true;
			setResponseStatus(event, error.statusCode, error.statusMessage);
			if (!event.handled) {
				if (error.unhandled || error.fatal) console.error('[h3]', error.fatal ? '[fatal]' : '[unhandled]', error);
				setResponseStatus(event, error.statusCode, error.statusMessage);
				res.setHeader('content-type', 'application/json');
				res.end(JSON.stringify({ statusCode: error.statusCode, statusMessage: error.statusMessage, stack: [], data: error.data }, undefined, 2));
			}
		}
		return toResponse(event);
	}
}

function toResponse(event: H3Event): Response {
	const { res, req } = event.node;
	const headers = new Headers();
	for (const [name, value] of Object.entries(res.getHeaders())) {
		if (name === 'set-cookie') {
			for (const cookie of splitCookiesString((Array.isArray(value) ? value : [value]).map(String))) headers.append('set-cookie', cookie);
		} else {
			headers.set(name, Array.isArray(value) ? value.join(', ') : String(value));
		}
	}
	const nullBody = [101, 204, 205, 304].includes(res.statusCode) || req.method.toUpperCase() === 'HEAD';
	return new Response(nullBody ? null : (res.body as BodyInit | undefined) ?? null, { status: res.statusCode, statusText: res.statusMessage ?? '', headers });
}

export interface WindowOptions {
	/** `runtimeConfig` de nuxt.config, sans le .env (Vitest ne le lit pas). */
	runtimeConfig: Record<string, any>;
}

/** Prépare la fenêtre avant que ses clés ne deviennent globales (setupWindow). Rend la fonction de nettoyage. */
export function setupWindow(win: EnvironmentWindow, options: WindowOptions): () => void {
	win.__NUXT_VITEST_ENVIRONMENT__ = true;
	win.__NUXT__ = { serverRendered: false, config: options.runtimeConfig, data: {}, state: {} };
	const consoleInfo = console.info;
	console.info = (...args: unknown[]) => {
		if (args[0] === '<Suspense> is an experimental feature and its API will likely change.') return;
		return consoleInfo(...args);
	};
	for (const [tag, id] of [['div', '__nuxt'], ['div', 'teleports']]) {
		if (win.document.getElementById(id)) continue;
		const element = win.document.createElement(tag);
		element.setAttribute('id', id);
		win.document.body.appendChild(element);
	}
	const app = new RegistryApp();
	const registry = new Set<string>();
	const originalFetch = win.fetch;
	win.fetch = (async (input: RequestInfo | URL, init?: RequestInit) => {
		let url: string;
		let options = init;
		if (typeof input === 'string') url = input;
		else if (input instanceof URL) url = input.toString();
		else {
			url = input.url;
			options = { method: init?.method ?? input.method, body: init?.body ?? (input.body as BodyInit), headers: init?.headers ?? input.headers };
		}
		const base = url.split('?')[0];
		if (registry.has(base) || registry.has(url)) url = `/_${url}`;
		if (url.startsWith('/')) return app.handle(url, options, registry);
		return originalFetch(input, init);
	}) as typeof fetch;
	win.$fetch = createFetch({ fetch: win.fetch, Headers: win.Headers });
	win.__registry = registry;
	win.__app = app;
	return () => {
		console.info = consoleInfo;
	};
}

/** Configuration d'exécution du navigateur de test : celle de nuxt.config, comme la construit @nuxt/test-utils. */
export function testRuntimeConfig(userConfig: Record<string, any> | undefined): Record<string, any> {
	const { public: publicConfig, app: _app, nitro: _nitro, ...rest } = structuredClone(userConfig ?? {});
	return {
		public: publicConfig ?? {},
		app: { buildId: 'test', baseURL: '/', buildAssetsDir: '/_nuxt/', cdnURL: '' },
		...rest,
		nitro: { envPrefix: 'NUXT_' },
	};
}

/** `registerEndpoint(url, gestionnaire | { handler, method, once })`. */
export function registerEndpoint(url: string, options: EventHandler | Omit<EndpointConfig, 'url'>): () => void {
	const win = globalThis as unknown as EnvironmentWindow;
	const app = win.__app;
	if (!app) throw new Error('registerEndpoint() can only be used in a `@nuxt/test-utils` runtime environment');
	const config: EndpointConfig = typeof options === 'function' ? { url, handler: options, method: undefined, once: false } : { ...options, url };
	config.handler = Object.assign(config.handler, { __is_handler__: true as const });
	app.endpoints[url] ||= [];
	app.endpoints[url].push(config);
	win.__registry!.add(url);
	return () => {
		app.endpoints[url]?.splice(app.endpoints[url].indexOf(config), 1);
		if (app.endpoints[url]?.length === 0) win.__registry!.delete(url);
	};
}
