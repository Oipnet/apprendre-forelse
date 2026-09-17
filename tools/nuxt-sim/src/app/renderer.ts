/**
 * Le « serveur de développement » de l'application : rend une page côté serveur (comme le renderer de
 * @nuxt/nitro-server) et sert sous /_nuxt/ les modules que le navigateur charge pour hydrater la page
 * (comme Vite). Toutes les requêtes de l'aperçu passent par le simulateur : c'est lui qui répond aux deux.
 */
import { stringify, uneval } from 'devalue';
import { init as initLexer, parse as parseImports } from 'es-module-lexer';
import { transform } from 'sucrase';
import * as vue from 'vue';
import { renderToString } from 'vue/server-renderer';
import * as serverRenderer from 'vue/server-renderer';
import * as vueRouter from 'vue-router';
import { ModuleLoader, cannotFindModule, resolveProjectImport, type ProjectFiles } from '../compiler.ts';
import { createError } from '../h3/error.ts';
import type { H3Event } from '../h3/event.ts';
import { setResponseHeader } from '../h3/utils.ts';
import type { RuntimeConfig } from '../nitro/config.ts';
import { NUXT_AUTO_IMPORTS, VUE_AUTO_IMPORTS, addStateKeys, importTable, injectAutoImports, type ImportTable } from './auto-imports.ts';
import { scanApp, type AppStructure, type PageRoute } from './pages.ts';
import { scanComponents, scanImports, type ScannedComponent } from './scan.ts';
import { createNuxtApp, payloadReducers, type AppManifest, type ManifestRoute } from './runtime/app.ts';
import * as nuxtComposables from './runtime/composables.ts';
import { compileSfc } from './sfc.ts';

const ASSETS = '/_nuxt/';
const APP_CONFIG = 'app/app.config.ts';
const RUNTIME = `${ASSETS}@nuxt-sim/`;

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

	constructor(
		private readonly files: ProjectFiles,
		private readonly runtimeConfig: RuntimeConfig,
		private readonly clientBundle?: string,
		/** Le `$fetch` de Nitro : côté serveur, l'application appelle les routes sans réseau. */
		private readonly serverFetch?: unknown,
	) {
		this.structure = scanApp(files);
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
		});
	}

	/** Rend la page demandée ; une URL sans page correspondante donne l'erreur 404 de Nuxt. */
	async render(event: H3Event): Promise<string> {
		const url = event.path;
		const nuxtApp = await createNuxtApp(this.serverManifest(), {
			url,
			runtimeConfig: this.runtimeConfig,
			ssrContext: { url, event, $fetch: this.serverFetch },
		});
		const route = nuxtApp.router.currentRoute.value;
		if (this.structure.pages && route.matched.length === 0) {
			const message = `Page not found: ${route.fullPath}`;
			throw createError({ statusCode: 404, statusMessage: message, message, data: { path: route.fullPath } });
		}
		// Comme Nuxt (app.config.errorHandler, puis showError) : une erreur de rendu est recueillie, pas laissée
		// filer en promesse rejetée, et la page d'erreur remplace la page.
		let renderError: unknown;
		nuxtApp.vueApp.config.errorHandler = (error) => {
			renderError ??= error;
		};
		const appHtml = await renderToString(nuxtApp.vueApp, {}).catch((error) => {
			renderError ??= error;
			return '';
		});
		if (renderError !== undefined) {
			throw renderError;
		}
		const config = {
			public: this.runtimeConfig.public,
			app: { baseURL: this.runtimeConfig.app.baseURL, buildId: 'dev', buildAssetsDir: this.runtimeConfig.app.buildAssetsDir, cdnURL: this.runtimeConfig.app.cdnURL },
		};
		setResponseHeader(event, 'content-type', 'text/html;charset=utf-8');
		setResponseHeader(event, 'x-powered-by', 'Nuxt');
		return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
			+ `<script type="module" src="${this.assetsUrl}@nuxt-sim/entry.js" crossorigin></script></head>`
			+ `<body><div id="__nuxt">${appHtml}</div><div id="teleports"></div>`
			+ `<script type="application/json" data-nuxt-logs="nuxt-app">${stringify(nuxtApp.logs)}</script>`
			+ `<script>window.__NUXT__={};window.__NUXT__.config=${uneval(config)}</script>`
			+ `<script type="application/json" data-nuxt-data="nuxt-app" data-ssr="true" id="__NUXT_DATA__">${stringify(nuxtApp.payload, payloadReducers).replaceAll('/', '\\u002F')}</script>`
			+ '</body></html>';
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
		const file = decodeURIComponent(path.slice(ASSETS.length).split('?')[0]);
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
		const load = (file: string) => async () => this.serverLoader.load(file) as { default: vue.Component };
		const toRoutes = (routes: PageRoute[]): ManifestRoute[] => routes.map((route) => ({
			name: route.name,
			path: route.path,
			meta: route.meta ? (new Function(`return (${route.meta})`)() as Record<string, unknown>) : {},
			component: load(route.file),
			children: toRoutes(route.children),
		}));
		return {
			pages: this.structure.pages,
			app: this.structure.app ? load(this.structure.app) : undefined,
			layouts: Object.fromEntries(Object.entries(this.structure.layouts).map(([name, file]) => [name, load(file)])),
			routes: toRoutes(this.structure.routes),
			components: Object.fromEntries(this.components.map((component) => [component.pascalName, load(component.file)])),
			appConfig: this.files.has(APP_CONFIG) ? async () => this.serverLoader.load(APP_CONFIG) : undefined,
		};
	}

	private clientManifest(): string {
		const importer = (file: string) => `() => import(${JSON.stringify(this.assetsUrl + encodeURI(file))})`;
		const routes = (list: PageRoute[]): string => `[${list.map((route) => `{ name: ${JSON.stringify(route.name)}, path: ${JSON.stringify(route.path)}, meta: ${route.meta ?? '{}'}, component: ${importer(route.file)}, children: ${routes(route.children)} }`).join(', ')}]`;
		const layouts = Object.entries(this.structure.layouts).map(([name, file]) => `${JSON.stringify(name)}: ${importer(file)}`).join(', ');
		const components = this.components.map((component) => `${JSON.stringify(component.pascalName)}: ${importer(component.file)}`).join(', ');
		return `export default {\n\tpages: ${this.structure.pages},\n\tapp: ${this.structure.app ? importer(this.structure.app) : 'undefined'},\n\tlayouts: { ${layouts} },\n\troutes: ${routes(this.structure.routes)},\n\tcomponents: { ${components} },\n\tappConfig: ${this.files.has(APP_CONFIG) ? importer(APP_CONFIG) : 'undefined'},\n};\n`;
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

/** Compilation d'un .vue si besoin, `import.meta.*` de Nuxt, retrait des types, clés de useState, auto-imports. */
function prepareModule(path: string, source: string, ssr: boolean, imports: ImportTable): string {
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
