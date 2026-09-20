import { fileURLToPath } from 'node:url';
import { defineConfig, type Plugin } from 'vite';
import symfonyPlugin from 'vite-plugin-symfony';
import { discoverRuntimes, runtimesVirtualModule } from './discover-runtimes.ts';

/**
 * Les paquets @php-wasm importent leurs binaires (`import url from './php.wasm'`)
 * et attendent une URL : on ajoute `?url` pour que Vite les traite en assets.
 */
function phpWasmAssets(): Plugin {
	return {
		name: 'php-wasm-assets',
		enforce: 'pre',
		async resolveId(source, importer) {
			if (!/\.(wasm|so|dat)$/.test(source) || !importer?.includes('@php-wasm')) return null;
			const resolved = await this.resolve(`${source}?url`, importer, { skipSelf: true });
			return resolved?.id ?? null;
		},
	};
}

/**
 * happy-dom (le DOM des tests de composants) est écrit pour Node : ses imports de modules Node pointent
 * vers de petites doublures (stubs/node/), et les globales que Node fournit sont posées avant son chargement
 * (src/runtime/happy-dom-node.ts).
 */
const nodeStub = (name: string) => fileURLToPath(new URL(`./stubs/node/${name}`, import.meta.url));
const nodeShims = [
	{ find: /^(node:)?(fs|fs\/promises|net|http|https|zlib|child_process|dns|tls|os|path|stream|util|crypto|events|worker_threads|ws|buffer-image-size)$/, replacement: nodeStub('empty.js') },
	{ find: /^(node:)?vm$/, replacement: nodeStub('vm.js') },
	{ find: /^(node:)?url$/, replacement: nodeStub('url.js') },
	{ find: /^(node:)?perf_hooks$/, replacement: nodeStub('perf.js') },
	{ find: /^(node:)?stream\/web$/, replacement: nodeStub('streamweb.js') },
	{ find: /^node:buffer$/, replacement: 'buffer' },
];

/**
 * Seul PHP 8.4 est embarqué : les autres versions pointent vers un module vide.
 * Les « overrides » npm évitent déjà de télécharger leurs binaires, mais npm enregistre
 * mal le chemin de ces paquets locaux (liens cassés après `npm ci`) : l'alias ne dépend pas d'eux.
 */
const unusedPhpVersions = {
	find: /^@php-wasm\/(web|node)-(5-2|7-4|8-0|8-1|8-2|8-3|8-5)$/,
	replacement: fileURLToPath(new URL('./stubs/php-wasm-unused/index.js', import.meta.url)),
};

/**
 * Les runtimes installés, et ce qu'ils demandent au build.
 *
 * Le moteur ne nomme aucun d'eux : il lit ses dépendances, et chaque paquet dit lui-même de quoi il a
 * besoin (greffons, constantes, alias). Ajouter un runtime, c'est `npm install` puis reconstruire.
 */
const runtimes = await discoverRuntimes();
const runtimePlugins = () => runtimes.flatMap((r) => (r.build?.plugins?.() ?? []) as Plugin[]);
const runtimeDefine = Object.assign({}, ...runtimes.map((r) => r.build?.define ?? {})) as Record<string, string>;
const runtimeAlias = runtimes.flatMap((r) => r.build?.alias ?? []);

export default defineConfig({
	// Le build est servi par Symfony (platform/public/build), via pentatrion/vite-bundle.
	base: '/build/',
	publicDir: false,
	// Les greffons des runtimes ici aussi : en dev, Vite transforme les modules des workers avec les
	// plugins principaux.
	plugins: [phpWasmAssets(), runtimesVirtualModule(runtimes), ...runtimePlugins(), symfonyPlugin({ servePublic: false })],
	resolve: { alias: [unusedPhpVersions, ...nodeShims, ...runtimeAlias] },
	define: runtimeDefine,
	server: {
		port: 5173,
		strictPort: true,
		cors: true, // les pages Symfony (autre origine) chargent les modules en dev
		// Les paquets de runtime vivent hors du dossier du playground (liens npm vers ../tools, ../packages).
		fs: { allow: [fileURLToPath(new URL('..', import.meta.url))] },
	},
	optimizeDeps: {
		// Le pré-bundling casse les imports relatifs des binaires php-wasm…
		// …et, pour un paquet de runtime, la directive `?worker` de son worker : Vite l'empaquetterait
		// comme une dépendance ordinaire, et le worker ne serait jamais émis.
		exclude: ['@php-wasm/web', '@php-wasm/universal', '@php-wasm/web-8-4', ...runtimes.map((r) => r.name)],
		// …mais leurs dépendances CommonJS doivent, elles, être converties en ESM.
		include: ['@php-wasm/universal > ini'],
	},
	worker: { format: 'es', plugins: () => [phpWasmAssets(), runtimesVirtualModule(runtimes), ...runtimePlugins()] },
	build: {
		target: 'es2022',
		outDir: '../platform/public/build',
		emptyOutDir: true,
		rollupOptions: {
			input: {
				site: './src/entries/site.ts',
				playground: './src/entries/playground.ts',
				sandbox: './src/entries/sandbox.ts',
				atelier: './src/entries/atelier.ts',
			},
		},
	},
});
