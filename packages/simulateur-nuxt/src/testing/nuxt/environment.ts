/**
 * Un fichier de test dans l'environnement `nuxt` de @nuxt/test-utils : une fenêtre happy-dom dont les clés
 * deviennent globales (vitest-environment-nuxt), un runtime neuf (Vue, vue-router, Nuxt, @vue/test-utils)
 * évalué dans ce DOM, les fichiers du projet compilés pour le navigateur, puis l'application montée avant
 * le premier test (setupNuxt). Tout est retiré à la fin du fichier.
 */
import { NUXT_AUTO_IMPORTS, VUE_AUTO_IMPORTS, addStateKeys, importTable, injectAutoImports, type ImportTable } from '../../app/auto-imports.ts';
import { scanApp } from '../../app/pages.ts';
import { buildManifest, pageMetaSource, prepareModule } from '../../app/renderer.ts';
import { resolveAppHead } from '../../app/runtime/head.ts';
import { scanComponents, scanImports } from '../../app/scan.ts';
import { ModuleLoader, resolveProjectImport, type ProjectFiles } from '../../compiler.ts';
import { createError } from '../../h3/error.ts';
import * as h3 from '../../h3/utils.ts';
import { readNuxtConfig } from '../../nitro/config.ts';
import { transform } from 'sucrase';
import type { TestCaseResult } from '../types.ts';
import type { TestFile } from '../vitest.ts';
import { populateGlobal } from './dom.ts';
import { MOCKS_HELPER, transformTestMacros } from './macros.ts';
import { registerEndpoint, setupWindow, testRuntimeConfig, type EnvironmentWindow } from './window.ts';

/** Nom de la variable du script de runtime (voir src/node/test-runtime-bundle.ts). */
export const TEST_RUNTIME_GLOBAL = '__nuxtTestRuntime';

/** Ce que l'hôte fournit : dans le navigateur, des modules du build du playground ; sous Node, les paquets. */
export interface NuxtTestEnvironment {
	/** Code du runtime de test (buildTestRuntimeBundle). */
	runtime(): Promise<string>;
	/** Le module `happy-dom`. */
	happyDom(): Promise<{ Window: new (options: { url: string }) => any }>;
}

interface TestRuntime {
	vue: Record<string, unknown>;
	vueRouter: Record<string, unknown>;
	vueTestUtils: Record<string, unknown>;
	composables: Record<string, unknown>;
	setupNuxt(manifest: unknown): Promise<void>;
	createMountSuspended(imports: Record<string, unknown>, vi: unknown): unknown;
}

/** `happyDom.url` par défaut de @nuxt/test-utils. */
const TEST_URL = 'http://localhost:3000/';

export interface LoadedNuxtFile {
	/** Lance les tests une fois le fichier chargé, puis démonte l'environnement. */
	run(): Promise<TestCaseResult[]>;
	teardown(): Promise<void>;
}

/** Installe l'environnement et charge le fichier ; en cas d'échec au chargement, l'environnement est déjà retiré. */
export async function loadNuxtTestFile(files: ProjectFiles, path: string, testFile: TestFile, environment: NuxtTestEnvironment): Promise<LoadedNuxtFile> {
	const [code, { Window }] = await Promise.all([environment.runtime(), environment.happyDom()]);
	const nuxtConfig = readNuxtConfig(files);
	const win = new Window({ url: TEST_URL }) as EnvironmentWindow & { happyDOM: { abort(): Promise<void> } };
	const teardownWindow = setupWindow(win, { runtimeConfig: testRuntimeConfig(nuxtConfig.runtimeConfig) });
	const restoreGlobals = populateGlobal(globalThis, win, ['fetch', 'Request']);
	let tornDown = false;
	const teardown = async () => {
		if (tornDown) return;
		tornDown = true;
		testFile.unstubAllGlobals();
		for (const cleanup of (win.__cleanup ?? []).splice(0)) cleanup();
		restoreGlobals();
		teardownWindow();
		await win.happyDOM.abort();
	};
	try {
		const runtime = new Function(`${code}\nreturn ${TEST_RUNTIME_GLOBAL};`)() as TestRuntime;
		const api = testFile.api();
		const structure = scanApp(files);
		const components = scanComponents(files.keys());
		const projectImports = scanImports(files);
		// Dans un test, tout auto-import passe par `#imports` : mockNuxtImport peut remplacer aussi un composable du projet.
		const table: ImportTable = new Map([...importTable('#imports', projectImports).keys()].map((as) => [as, { from: '#imports', name: as }]));
		const imports: Record<string, unknown> = {
			...Object.fromEntries(VUE_AUTO_IMPORTS.map((name) => [name, runtime.vue[name]])),
			...Object.fromEntries(NUXT_AUTO_IMPORTS.map((name) => [name, runtime.composables[name]])),
		};
		const overrides = new Map<string, Record<string, unknown>>();
		const loader: ModuleLoader = new ModuleLoader(files, {}, {
			packages: {
				vue: runtime.vue,
				'vue-router': runtime.vueRouter,
				'#imports': imports,
				'#app': imports,
				'@vue/test-utils': runtime.vueTestUtils,
				vitest: api,
				h3: { ...h3, createError },
				'@nuxt/test-utils/runtime': {
					mountSuspended: runtime.createMountSuspended(imports, api.vi),
					registerEndpoint,
					renderSuspended: () => {
						throw new Error('renderSuspended demande @testing-library/vue, que le simulateur ne fournit pas : utilisez mountSuspended.');
					},
					...Object.fromEntries(['mockNuxtImport', 'unmockNuxtImport', 'mockComponent'].map((name) => [name, () => {
						throw new Error(`${name}() is a macro and it did not get transpiled. This may be an internal bug of @nuxt/test-utils.`);
					}])),
					[MOCKS_HELPER]: applyMocks,
				},
			},
			transform: (file, source) => (file === path ? prepareTestFile(file, source, table) : prepareModule(file, source, false, table)),
			virtual: (file) => pageMetaSource(structure, file),
			overrides,
		});
		for (const entry of projectImports) {
			if (entry.as in imports) continue;
			Object.defineProperty(imports, entry.as, { get: () => loader.load(entry.file)[entry.name], enumerable: true, configurable: true });
		}

		/** Préambule des macros : `mockNuxtImport`, `unmockNuxtImport` et `mockComponent` du fichier. */
		const originals = new Map<string, unknown>();
		async function applyMocks(mocks: [string, (original: unknown) => unknown][], unmocks: string[], componentMocks: [string, unknown][]) {
			for (const name of unmocks) {
				if (originals.has(name)) Object.defineProperty(imports, name, { value: originals.get(name), enumerable: true, configurable: true, writable: true });
			}
			for (const [name, factory] of mocks) {
				if (!originals.has(name)) originals.set(name, imports[name]);
				const value = await factory(originals.get(name));
				Object.defineProperty(imports, name, { value, enumerable: true, configurable: true, writable: true });
			}
			for (const [target, factory] of componentMocks) {
				const component = components.find((c) => c.pascalName === target || kebabCase(c.pascalName) === target);
				const file = component?.file ?? resolveProjectImport(files, target, path);
				if (!file) throw new Error(`mockComponent : composant « ${target} » introuvable.`);
				const result = (typeof factory === 'function' ? await factory() : await factory) as Record<string, unknown>;
				overrides.set(file, { __esModule: true, ...('default' in result ? result : { default: result }) });
			}
		}

		// Comme le fichier d'installation de @nuxt/test-utils (runtime/entry) : l'application avant tout test.
		(api.beforeAll as (fn: () => unknown) => void)(() => runtime.setupNuxt(
			buildManifest(structure, components, files.has('app/app.config.ts'), resolveAppHead(nuxtConfig.app?.head), loader),
		));
		await loader.loadWithTopLevelAwait(path);
		await testFile.collected();
	} catch (error) {
		await teardown();
		throw error;
	}
	return {
		async run() {
			try {
				return await testFile.run();
			} finally {
				await teardown();
			}
		},
		teardown,
	};
}

/** Fichier de test : types retirés, macros de @nuxt/test-utils, auto-imports (Nuxt les applique aussi aux tests). */
function prepareTestFile(path: string, source: string, table: ImportTable): string {
	let code = source
		.replace(/\bimport\.meta\.server\b|\bprocess\.server\b/g, 'false')
		.replace(/\bimport\.meta\.(?:client|browser)\b|\bprocess\.(?:client|browser)\b/g, 'true')
		.replace(/\bimport\.meta\.dev\b/g, 'true');
	code = transform(code, { transforms: ['typescript'], filePath: path, production: true, disableESTransforms: true }).code;
	code = transformTestMacros(code, { isImport: (name) => table.has(name) });
	return injectAutoImports(addStateKeys(code, path), table, path);
}

function kebabCase(name: string): string {
	return name.replace(/([a-z0-9])([A-Z])/g, '$1-$2').toLowerCase();
}
