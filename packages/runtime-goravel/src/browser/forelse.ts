import type { RuntimeManifest } from '@forelse/runtime-contract';
import { GoravelRuntime } from './GoravelRuntime.ts';

/**
 * Ce que ce paquet déclare au playground (voir `forelse.runtime` dans package.json) : le playground le
 * découvre parmi ses dépendances et l'enregistre, sans qu'aucun fichier du moteur ne nomme Goravel.
 */
export default {
	id: 'goravel-wasm',
	label: 'Goravel',
	create: () => new GoravelRuntime(),
} satisfies RuntimeManifest;
