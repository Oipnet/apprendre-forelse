/**
 * Le « serveur de développement » de l'application : rend une page côté serveur (comme le renderer de
 * @nuxt/nitro-server) et sert sous /_nuxt/ les modules que le navigateur charge pour hydrater la page
 * (comme Vite). Toutes les requêtes de l'aperçu passent par le simulateur : c'est lui qui répond aux deux.
 */
import { createHead, renderSSRHead } from '@unhead/vue/server';
import { stringify, uneval } from 'devalue';
import { encodePath, getQuery } from 'ufo';
import { init as initLexer, parse as parseImports } from 'es-module-lexer';
import { transform } from 'sucrase';
import * as vue from 'vue';
import { renderToString } from 'vue/server-renderer';
import * as serverRenderer from 'vue/server-renderer';
import * as vueRouter from 'vue-router';
import { ModuleLoader, cannotFindModule, resolveProjectImport, type ProjectFiles } from '../compiler.ts';
import { createError } from '../h3/error.ts';
import type { H3Event } from '../h3/event.ts';
import { setResponseHeader, setResponseHeaders, setResponseStatus } from '../h3/utils.ts';
import { destr } from '../unjs/destr.ts';
import type { RuntimeConfig } from '../nitro/config.ts';
import { NUXT_AUTO_IMPORTS, VUE_AUTO_IMPORTS, addStateKeys, importTable, injectAutoImports, type ImportTable } from './auto-imports.ts';
import { scanApp, type AppStructure, type PageRoute } from './pages.ts';
import { scanComponents, scanImports, type ScannedComponent } from './scan.ts';
import { UNHEAD_OPTIONS, createNuxtApp, payloadReducers, type AppManifest, type ManifestRoute } from './runtime/app.ts';
import { resolveAppHead } from './runtime/head.ts';
import type { NuxtLog } from './runtime/nuxt.ts';
import * as nuxtComposables from './runtime/composables.ts';
import { compileSfc } from './sfc.ts';

const ASSETS = '/_nuxt/';
const APP_CONFIG = 'app/app.config.ts';
const RUNTIME = `${ASSETS}@nuxt-sim/`;
/** Les méta d'une page (definePageMeta), servies à part comme dans Nuxt : `app/pages/x.vue?macro=true`. */
const MACRO = '?macro=true';

export interface Asset {
	type: string;
	body: string;
}

export class AppRenderer {
	private readonly structure: AppStructure;
	private readonly serverLoader: ModuleLoader;
	/** Préfixe public des modules (`app.baseURL` + `_nuxt/`) ; `asset()` reçoit, lui, un chemin sans le baseURL. */
	private readonly assetsUrl: string;
	private readonly components: ScannedComponent[];
	private readonly serverImports: ImportTable;
	private readonly clientImports: ImportTable;
	private readonly appHead: Record<string, any>;

	constructor(
		private readonly files: ProjectFiles,
		private readonly runtimeConfig: RuntimeConfig,
		private readonly clientBundle?: string,
		/** Le `$fetch` de Nitro : côté serveur, l'application appelle les routes sans réseau. */
		private readonly serverFetch?: unknown,
		/** `app.head` de nuxt.config. */
		appHead?: Record<string, any>,
	) {
		this.structure = scanApp(files);
		this.appHead = resolveAppHead(appHead);
		this.assetsUrl = `${runtimeConfig.app.baseURL}${ASSETS.slice(1)}`;
		this.components = scanComponents(files.keys());
		const projectImports = scanImports(files);
		this.serverImports = importTable('#imports', projectImports);
		this.clientImports = importTable(`${this.assetsUrl}@nuxt-sim/imports.js`, projectImports);
		const imports: Record<string, unknown> = {
			...Object.fromEntries(VUE_AUTO_IMPORTS.map((name) => [name, (vue as Record<string, unknown>)[name]])),
			...Object.fromEntries(NUXT_AUTO_IMPORTS.map((name) => [name, (nuxtComposables as Record<string, unknown>)[name]])),
		};
		if (serverFetch) imports.$fetch = serverFetch;
		this.serverLoader = new ModuleLoader(files, {}, {
			packages: { vue, 'vue/server-renderer': serverRenderer, 'vue-router': vueRouter, '#imports': imports, '#app': imports },
			transform: (path, source) => prepareModule(path, source, true, this.serverImports),
			virtual: (path) => this.macroSource(path),
		});
	}

	/** Module des méta d'une page : l'objet de definePageMeta, avec ses auto-imports (un middleware en ligne en a). */
	private macroSource(path: string): string | undefined {
		return pageMetaSource(this.structure, path);
	}

	/**
	 * Rend la page demandée (handlers/renderer de @nuxt/nitro-server). Une erreur montrée pendant le rendu est
	 * levée : le gestionnaire d'erreur la fait rendre par `/__nuxt_error`, requête interne qui arrive ici avec
	 * l'erreur dans sa query. Une redirection (navigateTo) remplace la page.
	 */
	async render(event: H3Event): Promise<string> {
		const internal = !!event.node.req.internal;
		let ssrError: Record<string, any> | null = null;
		if (event.path.startsWith('/__nuxt_error')) {
			if (!internal) {
				throw createError({ status: 404, statusText: 'Page Not Found: /__nuxt_error', message: 'Page Not Found: /__nuxt_error' });
			}
			ssrError = getQuery(event.path) as Record<string, any>;
		}
		const head = createHead(UNHEAD_OPTIONS as never);
		head.push(this.appHead);
		const ssrContext: NonNullable<Parameters<typeof createNuxtApp>[1]['ssrContext']> = {
			url: encodeEventPath(event.path),
			event,
			$fetch: this.serverFetch,
			head,
			error: false,
			payload: {},
		};
		if (ssrError) {
			const status = ssrError.status || ssrError.statusCode;
			if (status) ssrError.status = ssrError.statusCode = Number.parseInt(status);
			if (typeof ssrError.data === 'string') {
				try {
					ssrError.data = destr(ssrError.data);
				} catch {
					// Données illisibles : gardées telles quelles.
				}
			}
			ssrContext.error = true;
			ssrContext.payload = { error: ssrError };
			ssrContext.url = ssrError.url;
		}
		const logs: NuxtLog[] = [];
		const restoreConsole = captureConsole(logs);
		let nuxtApp: Awaited<ReturnType<typeof createNuxtApp>>;
		let appHtml: string;
		try {
			nuxtApp = await createNuxtApp(this.serverManifest(), { url: ssrContext.url, runtimeConfig: this.runtimeConfig, ssrContext, logs });
			if (ssrContext['~renderResponse']) {
				return this.sendRenderResponse(event, ssrContext['~renderResponse']);
			}
			appHtml = await renderToString(nuxtApp.vueApp, ssrContext).catch(async (error) => {
				const failure = (!ssrError && nuxtApp.payload.error) || error;
				await nuxtApp.hooks.callHook('app:error', failure);
				throw failure;
			});
		} finally {
			restoreConsole();
		}
		await nuxtApp.hooks.callHook('app:rendered', { ssrContext, renderResult: { html: appHtml } });
		if (ssrContext['~renderResponse']) {
			return this.sendRenderResponse(event, ssrContext['~renderResponse']);
		}
		if (nuxtApp.payload.error && !ssrError) {
			throw nuxtApp.payload.error;
		}
		const config = {
			public: this.runtimeConfig.public,
			app: { baseURL: this.runtimeConfig.app.baseURL, buildId: 'dev', buildAssetsDir: this.runtimeConfig.app.buildAssetsDir, cdnURL: this.runtimeConfig.app.cdnURL },
		};
		head.push({
			script: [
				{ 'type': 'application/json', 'innerHTML': stringify(nuxtApp.payload, payloadReducers).replaceAll('/', '\\u002F'), 'data-nuxt-data': 'nuxt-app', 'data-ssr': true, 'id': '__NUXT_DATA__' },
				{ innerHTML: `window.__NUXT__={};window.__NUXT__.config=${uneval(config)}` },
			],
		}, { tagPosition: 'bodyClose', tagPriority: 'high' });
		head.push({ script: [{ type: 'module', src: `${this.assetsUrl}@nuxt-sim/entry.js`, tagPosition: 'head', crossorigin: '' }] });
		const { headTags, bodyTags, bodyTagsOpen, htmlAttrs, bodyAttrs } = await renderSSRHead(head, { omitLineBreaks: true });
		const joinAttrs = (chunks: string[]) => (chunks.length === 0 ? '' : ` ${chunks.join(' ')}`);
		const chunks = (list: (string | undefined)[]) => list.map((chunk) => chunk?.trim()).filter((chunk): chunk is string => !!chunk);
		// Le plugin dev-server-logs de Nuxt place le journal du rendu en tête de la fin du document.
		const logsScript = `<script type="application/json" data-nuxt-logs="nuxt-app">${stringify(nuxtApp.logs)}</script>`;
		setResponseHeader(event, 'content-type', 'text/html;charset=utf-8');
		setResponseHeader(event, 'x-powered-by', 'Nuxt');
		return `<!DOCTYPE html><html${joinAttrs(htmlAttrs ? [htmlAttrs] : [])}><head>${chunks([headTags]).join('')}</head><body${joinAttrs(bodyAttrs ? [bodyAttrs] : [])}>`
			+ `${chunks([bodyTagsOpen]).join('')}<div id="__nuxt">${appHtml}</div><div id="teleports"></div>${logsScript}${bodyTags}</body></html>`;
	}

	/** Réponse posée par navigateTo côté serveur : en-têtes, statut et corps remplacent la page (defineRenderHandler). */
	private sendRenderResponse(event: H3Event, response: { statusCode: number; statusMessage?: string; body: string; headers: Record<string, string> }): string {
		if (response.headers) setResponseHeaders(event, response.headers);
		if (response.statusCode || response.statusMessage) setResponseStatus(event, response.statusCode, response.statusMessage);
		return response.body;
	}

	/** Module demandé sous /_nuxt/, ou `undefined` s'il n'existe pas. */
	async asset(path: string): Promise<Asset | undefined> {
		if (!path.startsWith(ASSETS)) {
			return undefined;
		}
		const javascript = (body: string): Asset => ({ type: 'text/javascript', body });
		switch (path) {
			case `${RUNTIME}client.js`:
				if (!this.clientBundle) {
					throw new Error('Module navigateur du simulateur absent (option clientBundle).');
				}
				return javascript(this.clientBundle);
			case `${RUNTIME}entry.js`:
				return javascript("import { startClient } from './client.js';\nimport manifest from './manifest.js';\nstartClient(manifest);\n");
			case `${RUNTIME}manifest.js`:
				return javascript(this.clientManifest());
			case `${RUNTIME}vue.js`:
				return javascript(reexport({ __vue: Object.keys(vue) }));
			case `${RUNTIME}vue-router.js`:
				return javascript(reexport({ __vueRouter: Object.keys(vueRouter) }));
			case `${RUNTIME}imports.js`:
				return javascript(reexport({ __vue: VUE_AUTO_IMPORTS, __nuxt: NUXT_AUTO_IMPORTS }));
		}
		const requested = decodeURIComponent(path.slice(ASSETS.length));
		if (requested.endsWith(MACRO)) {
			const source = this.macroSource(requested);
			return source === undefined ? undefined : javascript(await this.clientModule(requested, source));
		}
		const file = requested.split('?')[0];
		const source = this.files.get(file);
		if (source === undefined) {
			return undefined;
		}
		if (file.endsWith('.json')) {
			return javascript(`export default ${source.trim() || 'null'};\n`);
		}
		return javascript(await this.clientModule(file, source));
	}

	private serverManifest(): AppManifest {
		return buildManifest(this.structure, this.components, this.files.has(APP_CONFIG), this.appHead, this.serverLoader);
	}

	private clientManifest(): string {
		const url = (file: string) => JSON.stringify(this.assetsUrl + encodeURI(file));
		const importer = (file: string) => `() => import(${url(file)})`;
		// Les méta des pages sont importées d'emblée : un middleware en ligne est une fonction, pas du JSON.
		const metaImports: string[] = [];
		const routes = (list: PageRoute[]): string => `[${list.map((route) => {
			let meta = '{}';
			if (route.meta) {
				meta = `meta${metaImports.length}`;
				metaImports.push(`import ${meta} from ${JSON.stringify(this.assetsUrl + encodeURI(route.file) + MACRO)};`);
			}
			return `{ name: ${JSON.stringify(route.name)}, path: ${JSON.stringify(route.path)}, meta: ${meta}, component: ${importer(route.file)}, children: ${routes(route.children)} }`;
		}).join(', ')}]`;
		const routeList = routes(this.structure.routes);
		const layouts = Object.entries(this.structure.layouts).map(([name, file]) => `${JSON.stringify(name)}: ${importer(file)}`).join(', ');
		const components = this.components.map((component) => `${JSON.stringify(component.pascalName)}: ${importer(component.file)}`).join(', ');
		const namedMiddleware = Object.entries(this.structure.middleware.named).map(([name, file]) => `${JSON.stringify(name)}: ${importer(file)}`).join(', ');
		return `${metaImports.join('\n')}\nexport default {\n\tpages: ${this.structure.pages},\n\tapp: ${this.structure.app ? importer(this.structure.app) : 'undefined'},\n\tlayouts: { ${layouts} },\n\troutes: ${routeList},\n\tcomponents: { ${components} },\n\tappConfig: ${this.files.has(APP_CONFIG) ? importer(APP_CONFIG) : 'undefined'},\n\terror: ${this.structure.error ? importer(this.structure.error) : 'undefined'},\n\tmiddleware: { global: [${this.structure.middleware.global.map(importer).join(', ')}], named: { ${namedMiddleware} } },\n\tappHead: ${JSON.stringify(this.appHead)},\n};\n`;
	}

	/** Module navigateur d'un fichier du projet : imports réécrits vers des URL servies par le simulateur. */
	private async clientModule(file: string, source: string): Promise<string> {
		const code = prepareModule(file, source, false, this.clientImports);
		await initLexer;
		const [imports] = parseImports(code);
		let result = code;
		for (const entry of [...imports].reverse()) {
			if (!entry.n) continue;
			const target = clientImportUrl(this.files, entry.n, file, this.assetsUrl);
			result = result.slice(0, entry.s) + target + result.slice(entry.e);
		}
		return result;
	}
}

/** Manifeste de l'application dont les modules sont chargés par `loader` (côté serveur, ou dans un fichier de test). */
export function buildManifest(structure: AppStructure, components: ScannedComponent[], hasAppConfig: boolean, appHead: Record<string, any>, loader: ModuleLoader): AppManifest {
	const load = (file: string) => async () => loader.load(file) as { default: vue.Component };
	const toRoutes = (routes: PageRoute[]): ManifestRoute[] => routes.map((route) => ({
		name: route.name,
		path: route.path,
		meta: route.meta ? ((loader.load(route.file + MACRO).default ?? {}) as Record<string, unknown>) : {},
		component: load(route.file),
		children: toRoutes(route.children),
	}));
	return {
		pages: structure.pages,
		app: structure.app ? load(structure.app) : undefined,
		layouts: Object.fromEntries(Object.entries(structure.layouts).map(([name, file]) => [name, load(file)])),
		routes: toRoutes(structure.routes),
		components: Object.fromEntries(components.map((component) => [component.pascalName, load(component.file)])),
		appConfig: hasAppConfig ? async () => loader.load(APP_CONFIG) : undefined,
		error: structure.error ? load(structure.error) : undefined,
		middleware: {
			global: structure.middleware.global.map((file) => load(file) as never),
			named: Object.fromEntries(Object.entries(structure.middleware.named).map(([name, file]) => [name, load(file) as never])),
		},
		appHead,
	};
}

/** Module des méta d'une page (`app/pages/x.vue?macro=true`) : l'objet de definePageMeta. */
export function pageMetaSource(structure: AppStructure, path: string): string | undefined {
	if (!path.endsWith(MACRO)) return undefined;
	const route = findRoute(structure.routes, path.slice(0, -MACRO.length));
	return `export default (${route?.meta ?? '{}'})\n`;
}

/**
 * Plugin dev-server-logs de Nuxt : ce que le serveur écrit dans la console pendant un rendu (avertissements de
 * vue-router, console.log d'une page…) rejoint le journal transmis à la page. La console affiche toujours.
 */
const CONSOLE_LEVELS = { error: 0, warn: 1, log: 2, info: 3 } as const;

function captureConsole(logs: NuxtLog[]): () => void {
	const originals = Object.fromEntries(Object.keys(CONSOLE_LEVELS).map((type) => [type, console[type as keyof typeof CONSOLE_LEVELS]])) as Record<keyof typeof CONSOLE_LEVELS, (...args: unknown[]) => void>;
	for (const type of Object.keys(CONSOLE_LEVELS) as (keyof typeof CONSOLE_LEVELS)[]) {
		console[type] = (...args: unknown[]) => {
			logs.push({ date: new Date(), args, type, level: CONSOLE_LEVELS[type], tag: '', filename: '', stack: [] });
			originals[type](...args);
		};
	}
	return () => Object.assign(console, originals);
}

function findRoute(routes: PageRoute[], file: string): PageRoute | undefined {
	for (const route of routes) {
		if (route.file === file) return route;
		const child = findRoute(route.children, file);
		if (child) return child;
	}
	return undefined;
}

/** Chemin de l'événement encodé comme par Nuxt (createSSRContext) : la query est gardée telle quelle. */
function encodeEventPath(path: string): string {
	const queryIndex = path.indexOf('?');
	if (queryIndex === -1) return encodePath(path);
	return encodePath(path.slice(0, queryIndex)) + path.slice(queryIndex);
}

/** Compilation d'un .vue si besoin, `import.meta.*` de Nuxt, retrait des types, clés de useState, auto-imports. */
export function prepareModule(path: string, source: string, ssr: boolean, imports: ImportTable): string {
	let code = path.endsWith('.vue') ? compileSfc(path, source, ssr) : source;
	code = code
		.replace(/\bimport\.meta\.server\b|\bprocess\.server\b/g, String(ssr))
		.replace(/\bimport\.meta\.(?:client|browser)\b|\bprocess\.(?:client|browser)\b/g, String(!ssr))
		.replace(/\bimport\.meta\.dev\b/g, 'true');
	if (ssr) {
		code = defineServerGlobals(code);
	}
	code = transform(code, { transforms: ['typescript'], filePath: path, production: true, disableESTransforms: true }).code;
	return injectAutoImports(addStateKeys(code, path), imports, path);
}

/**
 * `define` du build serveur de Nuxt (@nuxt/vite-builder) : côté serveur, ces globales du navigateur valent
 * `undefined`. `window.innerWidth` échoue donc sur « Cannot read properties of undefined », et
 * `typeof window` vaut 'undefined'. Les autres (`localStorage`…) restent « is not defined ».
 */
const SERVER_UNDEFINED_GLOBALS = /(?<![\w$.])(window|document|navigator|location|XMLHttpRequest)(?![\w$])(?!\s*:(?!:))/g;

function defineServerGlobals(code: string): string {
	const stripped = stripForScan(code);
	let result = '';
	let last = 0;
	for (const match of stripped.matchAll(SERVER_UNDEFINED_GLOBALS)) {
		const index = match.index!;
		// Déclaration ou raccourci d'objet (`const location =`, `{ location }`) : ce n'est pas la globale.
		const before = stripped.slice(Math.max(0, index - 12), index);
		if (/(?:const|let|var|function|class)\s+$/.test(before)) continue;
		result += `${code.slice(last, index)}undefined`;
		last = index + match[0].length;
	}
	return result + code.slice(last);
}

/** Copie de même longueur, sans commentaires ni contenu de chaînes : les positions restent celles du code. */
function stripForScan(code: string): string {
	return code
		.replace(/\/\*[\s\S]*?\*\//g, (m) => ' '.repeat(m.length))
		.replace(/(^|[^:\\])\/\/.*$/gm, (m, p) => p + ' '.repeat(m.length - p.length))
		.replace(/'(?:\\.|[^'\\\n])*'|"(?:\\.|[^"\\\n])*"|`(?:\\.|[^`\\])*`/g, (m) => m[0] + ' '.repeat(m.length - 2) + m[0]);
}

function clientImportUrl(files: ProjectFiles, specifier: string, from: string, assetsUrl: string): string {
	switch (specifier) {
		case 'vue': return `${assetsUrl}@nuxt-sim/vue.js`;
		case 'vue-router': return `${assetsUrl}@nuxt-sim/vue-router.js`;
		case '#app':
		case '#imports': return `${assetsUrl}@nuxt-sim/imports.js`;
	}
	if (specifier.startsWith(`${assetsUrl}@nuxt-sim/`)) {
		return specifier;
	}
	const resolved = resolveProjectImport(files, specifier, from);
	if (resolved) {
		return assetsUrl + encodeURI(resolved);
	}
	throw new Error(resolved === null ? cannotFindModule(specifier, from) : `Le simulateur ne fournit pas le paquet « ${specifier} » (importé par ${from})`);
}

/** Module qui réexporte, sous leur nom, des membres des espaces de noms exposés par client.js. */
function reexport(namespaces: Record<string, string[]>): string {
	const lines = [`import { ${Object.keys(namespaces).join(', ')} } from './client.js';`];
	for (const [namespace, names] of Object.entries(namespaces)) {
		const identifiers = names.filter((name) => /^[A-Za-z_$][\w$]*$/.test(name) && name !== 'default');
		lines.push(`export const { ${identifiers.join(', ')} } = ${namespace};`);
	}
	return `${lines.join('\n')}\n`;
}
