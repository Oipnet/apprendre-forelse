import type { RuntimeManifest } from '@forelse/runtime-contract';
import { NuxtRuntime } from './NuxtRuntime';
import { m } from '../i18n/index.ts';

/**
 * Ce que ce paquet déclare au playground (voir `forelse.runtime` dans package.json).
 *
 * Le playground le découvre parmi ses dépendances et l'enregistre : aucun fichier du moteur ne nomme
 * Nuxt, ni ce paquet.
 */
export default {
	id: 'nuxt-sim',
	label: m.runtime.name,
	create: () => new NuxtRuntime(),
} satisfies RuntimeManifest;
