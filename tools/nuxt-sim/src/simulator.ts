/**
 * Serveur Nuxt simulé : reçoit une requête HTTP, la fait passer par le routeur de Nitro et les
 * gestionnaires de `server/`, et rend la réponse qu'aurait donnée `nuxi dev`.
 */
import { AppRenderer } from './app/renderer.ts';
import { hasApp } from './app/pages.ts';
import { ModuleLoader, type ProjectFiles } from './compiler.ts';
import { createError } from './h3/error.ts';
import { H3Event, SimulatedRequest, SimulatedResponse } from './h3/event.ts';
import * as h3 from './h3/utils.ts';
import { defaultReason, type HttpRequest, type HttpResponse } from './http.ts';
import { buildRuntimeConfig, type RuntimeConfig } from './nitro/config.ts';
import { respondWithError } from './nitro/errors.ts';
import { scanServerRoutes } from './nitro/scan.ts';
import { runTests } from './testing/run.ts';
import type { Grading, TestRunResult } from './testing/types.ts';
import { RadixRouter } from './unjs/radix3.ts';
import { transform } from 'sucrase';
import type { Storage } from 'unstorage';
import { importTable, injectAutoImports, type ImportTable } from './app/auto-imports.ts';
import { SERVER_IMPORT_DIRS, scanImports } from './app/scan.ts';
import { createNitroStorage, useStorageOf } from './nitro/storage.ts';
import { decodePath } from './unjs/ufo.ts';
import { createFetch, type $Fetch } from 'ofetch';
import { createCache } from './nitro/cache.ts';
import { parseDotenv } from './nitro/dotenv.ts';
import { NETWORK_FILE, SimulatedNetwork, parseNetworkFile, type RecordedResponse } from './nitro/network.ts';

export interface SimulatorOptions {
	/** Variables d'environnement vues par le serveur (NUXT_…). */
	env?: Record<string, string | undefined>;
	/**
	 * Module navigateur du runtime (Vue, vue-router, composants de Nuxt), servi à l'aperçu pour hydrater
	 * les pages : produit par `buildClientBundle()` (src/node) ou par le build du playground.
	 */
	clientBundle?: string;
	/**
	 * `app.baseURL` de Nuxt : préfixe sous lequel le projet est servi (l'aperçu de la plateforme vit sous
	 * /preview/<relais>/). Routes, pages, liens et modules y sont tous rangés. Par défaut « / ».
	 */
	baseURL?: string;
	/**
	 * Appels sortants (`$fetch` vers une URL absolue). Par défaut, les réponses enregistrées de
	 * `reseau.json` : le bac à sable n'a pas Internet. La conformité y branche le vrai réseau.
	 */
	network?: (input: string | URL | Request, init?: RequestInit) => Promise<Response>;
}

/** Ce que `import { reseau, horloge } from 'bac-a-sable'` donne aux tests d'un exercice. */
export interface SandboxApi {
	reseau: {
		/** Nombre d'appels à cette URL (query ignorée), ou à toutes. */
		appels(url?: string): number;
		/** Les appels, avec leur URL complète et leurs en-têtes. */
		requetes(url?: string): { methode: string; url: string; entetes: Record<string, string> }[];
		/** Impose une réponse à cette URL jusqu'à `retablir()` : panne, lenteur, coupure… */
		simuler(url: string, reponse: RecordedResponse): void;
		retablir(): void;
	};
	horloge: {
		/** Avance l'heure du serveur (celle du cache de Nitro), en secondes. */
		avancer(secondes: number): void;
	};
}

interface RouteEntry {
	path: string;
	/** Fichier du gestionnaire par méthode en minuscules, ou « all ». */
	handlers: Record<string, string>;
}

interface Build {
	router: RadixRouter<RouteEntry>;
	loader: ModuleLoader;
	/** Absent quand le projet n'a ni app/app.vue ni app/pages. */
	app?: AppRenderer;
}

/** `nuxi dev` indente le JSON renvoyé par les routes. */
const JSON_SPACE = 2;

export class NuxtSimulator {
	private readonly files: ProjectFiles;
	private build?: Build;
	/** useStorage : vit aussi longtemps que le simulateur, à travers les modifications de fichiers. */
	private readonly storage: Storage = createNitroStorage();
	/** Réponses enregistrées et appels sortants (voir src/nitro/network.ts). */
	readonly network = new SimulatedNetwork(() => parseNetworkFile(this.files.get(NETWORK_FILE)));
	/** Décalage de l'horloge du serveur, avancée par les tests. */
	private clockOffset = 0;
	/**
	 * `$fetch` global de Nitro : un chemin (« /api/… ») est servi par le simulateur lui-même, sans réseau,
	 * sous `app.baseURL`. Sans en-têtes ajoutés, comme node-mock-http (seul `host` vaut « localhost »).
	 */
	private get $fetch(): $Fetch {
		this._fetch ??= createFetch({
			fetch: (input, init) => this.localFetch(input, init),
			Headers,
			defaults: { baseURL: this.baseURL() },
		});
		return this._fetch;
	}

	private _fetch?: $Fetch;

	constructor(
		files: Record<string, string> | ProjectFiles = {},
		private readonly options: SimulatorOptions = {},
	) {
		this.files = new Map(files instanceof Map ? files : Object.entries(files));
	}

	writeFile(path: string, content: string): void {
		this.files.set(path, content);
		this.build = undefined;
	}

	deleteFile(path: string): void {
		this.files.delete(path);
		this.build = undefined;
	}

	readFile(path: string): string | null {
		return this.files.get(path) ?? null;
	}

	listFiles(): string[] {
		return [...this.files.keys()].sort();
	}

	/**
	 * Lance les tests Vitest du projet. Chaque fichier de test a son serveur neuf, servi à la racine
	 * (sans baseURL : @nuxt/test-utils joint le chemin à l'adresse du serveur).
	 */
	now(): number {
		return Date.now() + this.clockOffset;
	}

	sandbox(): SandboxApi {
		return {
			reseau: {
				appels: (url) => this.network.callsTo(url).length,
				requetes: (url) => this.network.callsTo(url),
				simuler: (url, reponse) => this.network.override(url, reponse),
				retablir: () => this.network.restore(),
			},
			horloge: {
				avancer: (secondes) => {
					this.clockOffset += secondes * 1000;
				},
			},
		};
	}

	runTests(grading?: Grading, only?: string[]): Promise<TestRunResult> {
		return runTests(this.files, {
			grading,
			only,
			createServer: (files) => new NuxtSimulator(files, { ...this.options, baseURL: '/' }),
		});
	}

	async request(request: HttpRequest): Promise<HttpResponse> {
		const started = performance.now();
		const body = typeof request.body === 'string' ? new TextEncoder().encode(request.body) : request.body;
		const req = new SimulatedRequest(request.method, request.url, request.headers, body?.length ? body : undefined);
		const res = new SimulatedResponse();
		const event = new H3Event(req, res);
		try {
			await this.handle(event);
		} catch (error) {
			await respondWithError(error, event);
		}
		return toHttpResponse(req, res, performance.now() - started);
	}

	private async localFetch(input: string | URL | Request, init: RequestInit = {}): Promise<Response> {
		const url = typeof input === 'string' ? input : input instanceof URL ? input.href : input.url;
		if (!url.startsWith('/')) {
			return (this.options.network ?? this.network.fetch)(input, init);
		}
		const headers: Record<string, string> = {};
		new Headers(init.headers).forEach((value, name) => (headers[name] = value));
		headers.host ??= 'localhost';
		const body = init.body == null ? undefined : typeof init.body === 'string' ? init.body : new Uint8Array(await new Response(init.body).arrayBuffer());
		const response = await this.request({ method: (init.method ?? 'GET').toUpperCase(), url, headers, body });
		const nullBody = [101, 204, 205, 304].includes(response.status);
		return new Response(nullBody || init.method?.toUpperCase() === 'HEAD' ? null : (response.body as BodyInit), {
			status: response.status,
			statusText: response.statusText,
			headers: Object.entries(response.headers).flatMap(([name, values]) => values.map((value) => [name, value] as [string, string])),
		});
	}

	private async handle(event: H3Event): Promise<void> {
		const build = this.getBuild();
		const req = event.node.req;
		req.originalUrl = req.originalUrl || req.url || '/';
		event._path = decodeRequestPath(event._path || req.url || '/');
		event.context.nitro = { errors: [] };
		event.fetch = (request, init) => h3.fetchWithEvent(event, request, init as Record<string, any>, { fetch: (req, options) => this.localFetch(req, options as RequestInit) });
		event.$fetch = (request, init) => h3.fetchWithEvent(event, request, init, { fetch: this.$fetch as never });
		// Une tâche qui finit après la réponse (rafraîchir un cache) : ses erreurs sont signalées, pas perdues.
		event.waitUntil = (promise) => {
			promise.catch((error) => console.error('[nitro] waitUntil', error));
		};

		// `h3App.use(app.baseURL, router.handler)` : hors du préfixe, rien ne répond ; dedans, il est retiré.
		const base = this.baseURL();
		if (base !== '/') {
			if (!(`${event.path.split('?')[0]}/`).startsWith(base)) {
				throw createError({ statusCode: 404, statusMessage: `Cannot find any path matching ${event.path || '/'}.` });
			}
			event._path = event.path.slice(base.length - 1) || '/';
			if (event._path.startsWith('?')) event._path = `/${event._path}`;
		}

		// Comme le serveur de Vite devant Nitro : les modules de l'application passent en premier.
		const asset = await build.app?.asset(event.path);
		if (asset) {
			h3.setResponseHeader(event, 'content-type', asset.type);
			await h3.send(event, asset.body);
			return;
		}

		const value = await this.routerHandler(build, event);
		if (value !== undefined) {
			await h3.handleHandlerResponse(event, value, JSON_SPACE);
			return;
		}
		if (!event.handled) {
			throw createError({ statusCode: 404, statusMessage: `Cannot find any path matching ${event.path || '/'}.` });
		}
	}

	/** Routeur « préemptif » de Nitro : une route qui ne rend rien répond 204. */
	private async routerHandler(build: Build, event: H3Event): Promise<unknown> {
		const path = event.path.split('?')[0];
		const method = event.method.toLowerCase();
		const matched = build.router.lookup(path);
		let file = matched?.handlers[method] ?? matched?.handlers.all;
		if (matched && !file) {
			for (const candidate of build.router.matchAll(path).reverse()) {
				file = candidate.handlers[method] ?? candidate.handlers.all;
				if (file) break;
			}
		}
		if (!matched || !file) {
			// Le rendu des pages est la route attrape-tout de Nitro (`/**`).
			if (build.app) {
				return build.app.render(event);
			}
			// Sans app/ : le vrai Nuxt afficherait sa page d'accueil ; non vérifié par la conformité.
			throw createError({ statusCode: 404, statusMessage: `Page not found: ${path}` });
		}
		event.context.matchedRoute = matched;
		event.context.params = matched.params || {};

		const handler = build.loader.load(file).default;
		if (typeof handler !== 'function') {
			throw new TypeError(`Invalid lazy handler result. It should be a function: ${file}`);
		}
		const result = await handler(event);
		return result === undefined ? null : result;
	}

	private getBuild(): Build {
		if (this.build) {
			return this.build;
		}
		const runtimeConfig = this.loadRuntimeConfig();
		// Auto-imports de Nitro : h3, les utilitaires de Nitro, puis les exports de server/utils et shared/.
		const nitroImports: Record<string, unknown> = {
			...h3,
			createError,
			useRuntimeConfig: (event?: H3Event) => {
				if (!event) return runtimeConfig;
				return (event.context.nitro.runtimeConfig ??= structuredClone(runtimeConfig));
			},
			useStorage: useStorageOf(this.storage),
			$fetch: this.$fetch,
			...createCache({
				storage: this.storage,
				now: () => this.now(),
				$fetch: (request, init) => this.$fetch(request, init),
				localFetch: (request, init) => this.localFetch(request, init),
			}),
		};
		const serverImports = importTable('#imports', scanImports(this.files, SERVER_IMPORT_DIRS), Object.keys(nitroImports));
		const router = new RadixRouter<RouteEntry>();
		const entries = new Map<string, RouteEntry>();
		for (const route of scanServerRoutes([...this.files.keys()])) {
			let entry = entries.get(route.route);
			if (!entry) {
				entry = { path: route.route, handlers: {} };
				entries.set(route.route, entry);
				router.insert(route.route, entry);
			}
			entry.handlers[route.method ?? 'all'] = route.file;
		}
		const app = hasApp(this.files.keys()) ? new AppRenderer(this.files, runtimeConfig, this.options.clientBundle, this.$fetch) : undefined;
		const loader = new ModuleLoader(this.files, {}, {
			packages: { h3: nitroImports, '#imports': nitroImports, 'nitropack/runtime': nitroImports, '#nitro': nitroImports },
			transform: (path, source) => prepareServerModule(path, source, serverImports),
		});
		this.build = { router, loader, app };
		return this.build;
	}

	private baseURL(): string {
		const base = this.options.baseURL ?? '/';
		return `/${base.replace(/^\/+|\/+$/g, '')}/`.replace('//', '/');
	}

	private loadRuntimeConfig(): RuntimeConfig {
		const file = ['nuxt.config.ts', 'nuxt.config.js', 'nuxt.config.mjs'].find((name) => this.files.has(name));
		let userConfig: Record<string, any> = {};
		if (file) {
			const loader = new ModuleLoader(this.files, { defineNuxtConfig: (config: unknown) => config });
			userConfig = (loader.load(file).default ?? {}) as Record<string, any>;
		}
		// Comme nuxi dev : le .env du projet, sans écraser une variable déjà définie.
		const config = buildRuntimeConfig(userConfig.runtimeConfig, { ...parseDotenv(this.files.get('.env')), ...this.options.env });
		config.app.baseURL = this.baseURL();
		return config;
	}
}

/** Module de server/ : `import.meta.*` de Nitro, retrait des types, auto-imports. */
function prepareServerModule(path: string, source: string, imports: ImportTable): string {
	const code = transform(
		source.replace(/\bimport\.meta\.(?:server|dev)\b/g, 'true').replace(/\bimport\.meta\.(?:client|browser)\b/g, 'false'),
		{ transforms: ['typescript'], filePath: path, production: true, disableESTransforms: true },
	).code;
	return injectAutoImports(code, imports, path);
}

function decodeRequestPath(url: string): string {
	const index = url.indexOf('?');
	const path = index === -1 ? url : url.slice(0, index);
	const query = index === -1 ? '' : url.slice(index);
	return (path.includes('%25') ? decodePath(path.replace(/%25/g, '%2525')) : decodePath(path)) + query;
}

function toHttpResponse(req: SimulatedRequest, res: SimulatedResponse, durationMs: number): HttpResponse {
	const headers: Record<string, string[]> = {};
	for (const [name, value] of Object.entries(res.getHeaders())) {
		headers[name] = (Array.isArray(value) ? value : [value]).map(String);
	}
	let body = typeof res.body === 'string' ? new TextEncoder().encode(res.body) : (res.body ?? new Uint8Array());
	const noBody = req.method.toUpperCase() === 'HEAD' || res.statusCode === 204 || res.statusCode === 304;
	if (noBody) {
		body = new Uint8Array();
	} else if (!headers['content-length']) {
		// Node calcule la longueur quand la réponse part d'un bloc.
		headers['content-length'] = [String(body.byteLength)];
	}
	return { status: res.statusCode, statusText: res.statusMessage || defaultReason(res.statusCode), headers, body, durationMs };
}
