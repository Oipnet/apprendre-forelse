import { readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { defineConfig, type Plugin } from 'vite';
import symfonyPlugin from 'vite-plugin-symfony';
import { buildClientBundle } from '../tools/nuxt-sim/src/node/client-bundle.ts';
import { buildTestRuntimeBundle } from '../tools/nuxt-sim/src/node/test-runtime-bundle.ts';

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
 * Module navigateur du simulateur Nuxt (Vue, vue-router et runtime de Nuxt en un fichier), que le worker
 * Nuxt sert à l'aperçu pour hydrater les pages : construit par rolldown, livré au worker comme une chaîne.
 */
const nuxtSimRuntime = fileURLToPath(new URL('../tools/nuxt-sim/src/app/runtime', import.meta.url));
const nuxtSimTesting = fileURLToPath(new URL('../tools/nuxt-sim/src/testing', import.meta.url));

function nuxtSimClient(): Plugin {
	const id = 'virtual:nuxt-sim-client';
	return {
		name: 'nuxt-sim-client',
		resolveId: (source) => (source === id ? `\0${id}` : null),
		async load(resolved) {
			if (resolved !== `\0${id}`) return null;
			// Sans cela, le serveur de dev garderait le module construit au démarrage après une modification du runtime.
			for (const file of readdirSync(nuxtSimRuntime, { recursive: true, encoding: 'utf8' })) {
				if (file.endsWith('.ts')) this.addWatchFile(`${nuxtSimRuntime}/${file}`);
			}
			return `export default ${JSON.stringify(await buildClientBundle())};`;
		},
	};
}

/**
 * Runtime de l'environnement de test `nuxt` (Vue, vue-router, Nuxt et @vue/test-utils), évalué à neuf pour
 * chaque fichier de test qui monte des composants : livré au worker comme une chaîne, chargée à la demande.
 */
function nuxtSimTestRuntime(): Plugin {
	const id = 'virtual:nuxt-sim-test-runtime';
	return {
		name: 'nuxt-sim-test-runtime',
		resolveId: (source) => (source === id ? `\0${id}` : null),
		async load(resolved) {
			if (resolved !== `\0${id}`) return null;
			for (const file of readdirSync(nuxtSimTesting, { recursive: true, encoding: 'utf8' })) {
				if (file.endsWith('.ts')) this.addWatchFile(`${nuxtSimTesting}/${file}`);
			}
			return `export default ${JSON.stringify(await buildTestRuntimeBundle())};`;
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

export default defineConfig({
	// Le build est servi par Symfony (platform/public/build), via pentatrion/vite-bundle.
	base: '/build/',
	publicDir: false,
	// nuxtSimClient ici aussi : en dev, Vite transforme les modules des workers avec les plugins principaux.
	plugins: [phpWasmAssets(), nuxtSimClient(), nuxtSimTestRuntime(), symfonyPlugin({ servePublic: false })],
	resolve: { alias: [unusedPhpVersions, ...nodeShims] },
	// Drapeaux de compilation de Vue, que le simulateur Nuxt embarque dans son worker (rendu serveur).
	define: {
		__VUE_OPTIONS_API__: 'true',
		__VUE_PROD_DEVTOOLS__: 'false',
		__VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false',
	},
	server: {
		port: 5173,
		strictPort: true,
		cors: true, // les pages Symfony (autre origine) chargent les modules en dev
		// Le worker Nuxt importe le simulateur depuis tools/nuxt-sim, hors du dossier du playground.
		fs: { allow: [fileURLToPath(new URL('..', import.meta.url))] },
	},
	optimizeDeps: {
		// Le pré-bundling casse les imports relatifs des binaires php-wasm…
		exclude: ['@php-wasm/web', '@php-wasm/universal', '@php-wasm/web-8-4'],
		// …mais leurs dépendances CommonJS doivent, elles, être converties en ESM.
		include: ['@php-wasm/universal > ini'],
	},
	worker: { format: 'es', plugins: () => [phpWasmAssets(), nuxtSimClient(), nuxtSimTestRuntime()] },
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
