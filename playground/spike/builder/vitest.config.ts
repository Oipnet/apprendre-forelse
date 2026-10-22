import { defineConfig } from 'vitest/config';

/** Essai php-wasm-builder : lancé à la main ou par le workflow d'essai, jamais avec les tests du playground. */
export default defineConfig({
	test: {
		include: ['spike/builder/*.spike.ts'],
		testTimeout: 600_000,
		hookTimeout: 600_000,
	},
});
