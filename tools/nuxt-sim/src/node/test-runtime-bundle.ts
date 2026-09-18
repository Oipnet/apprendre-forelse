/**
 * Construit le runtime de l'environnement de test `nuxt` (src/testing/nuxt/runtime.ts avec Vue, vue-router
 * et @vue/test-utils) : un script qui rend ses exports, évalué à neuf pour chaque fichier de test une fois
 * le DOM installé (voir src/testing/run.ts). Vue en mode développement, comme sous Vitest.
 */
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { rolldown } from 'rolldown';
import { TEST_RUNTIME_GLOBAL } from '../testing/nuxt/environment.ts';

export async function buildTestRuntimeBundle(): Promise<string> {
	const require = createRequire(import.meta.url);
	// La version « browser » de @vue/test-utils est un script qui attend Vue en variable globale.
	const testUtils = join(dirname(require.resolve('@vue/test-utils/package.json')), 'dist/vue-test-utils.esm-bundler.mjs');
	const bundle = await rolldown({
		input: fileURLToPath(new URL('../testing/nuxt/runtime.ts', import.meta.url)),
		platform: 'browser',
		resolve: { alias: { '@vue/test-utils': testUtils } },
		transform: {
			define: {
				'process.env.NODE_ENV': '"development"',
				__VUE_OPTIONS_API__: 'true',
				__VUE_PROD_DEVTOOLS__: 'false',
				__VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'true',
			},
		},
	});
	const { output } = await bundle.generate({ format: 'iife', name: TEST_RUNTIME_GLOBAL, codeSplitting: false });
	await bundle.close();
	return output[0].code;
}
