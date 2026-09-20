import { readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import type { RuntimeBuildContribution } from '@forelse/runtime-contract';
import { buildClientBundle } from './client-bundle.ts';
import { buildTestRuntimeBundle } from './test-runtime-bundle.ts';

/**
 * Ce que ce runtime demande au build du playground (voir `forelse.vite` dans package.json).
 *
 * Deux modules virtuels et trois drapeaux Vue : c'était jusqu'ici dans `playground/vite.config.ts`,
 * qui importait ce paquet par chemin relatif. Le moteur n'a plus à savoir que Nuxt existe.
 */
const runtimeDir = fileURLToPath(new URL('../app/runtime', import.meta.url));
const testingDir = fileURLToPath(new URL('../testing', import.meta.url));

/** Un module virtuel qui livre au worker un bundle construit à la demande, sous forme de chaîne. */
function bundleVirtuel(id: string, watched: string, build: () => Promise<string>) {
	return {
		name: id,
		resolveId: (source: string) => (source === id ? `\0${id}` : null),
		async load(this: { addWatchFile: (f: string) => void }, resolved: string) {
			if (resolved !== `\0${id}`) return null;
			// Sans cela, le serveur de dev garderait le module construit au démarrage après une modification.
			for (const file of readdirSync(watched, { recursive: true, encoding: 'utf8' })) {
				if (file.endsWith('.ts')) this.addWatchFile(`${watched}/${file}`);
			}
			return `export default ${JSON.stringify(await build())};`;
		},
	};
}

export default {
	plugins: () => [
		// Module navigateur du simulateur (Vue, vue-router et runtime de Nuxt en un fichier), que le
		// worker sert à l'aperçu pour hydrater les pages.
		bundleVirtuel('virtual:nuxt-sim-client', runtimeDir, buildClientBundle),
		// Runtime de l'environnement de test (Vue, vue-router, Nuxt, @vue/test-utils), évalué à neuf pour
		// chaque fichier de test qui monte des composants.
		bundleVirtuel('virtual:nuxt-sim-test-runtime', testingDir, buildTestRuntimeBundle),
	],
	// Drapeaux de compilation de Vue, que le simulateur embarque dans son worker (rendu serveur).
	define: {
		__VUE_OPTIONS_API__: 'true',
		__VUE_PROD_DEVTOOLS__: 'false',
		__VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false',
	},
} satisfies RuntimeBuildContribution;
