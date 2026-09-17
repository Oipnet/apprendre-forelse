/**
 * Construit le module navigateur du runtime (src/app/runtime/client.ts avec Vue et vue-router) : Vue en
 * mode développement, pour que l'aperçu signale les erreurs d'hydratation comme `nuxi dev`.
 */
import { fileURLToPath } from 'node:url';
import { rolldown } from 'rolldown';

export async function buildClientBundle(): Promise<string> {
	const bundle = await rolldown({
		input: fileURLToPath(new URL('../app/runtime/client.ts', import.meta.url)),
		platform: 'browser',
		transform: {
			define: {
				'process.env.NODE_ENV': '"development"',
				__VUE_OPTIONS_API__: 'true',
				__VUE_PROD_DEVTOOLS__: 'false',
				__VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'true',
			},
		},
	});
	const { output } = await bundle.generate({ format: 'esm', codeSplitting: false });
	await bundle.close();
	return output[0].code;
}
