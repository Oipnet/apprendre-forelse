import { defineConfig } from 'vitest/config';

export default defineConfig({
	test: {
		include: ['tests/*.test.ts'],
		// Démarrer une instance de Goravel prend une à deux secondes ; les tests de notation en démarrent plusieurs.
		testTimeout: 60_000,
	},
});
