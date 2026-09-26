import { defineConfig } from 'vitest/config';

/**
 * Tests du playground : fonctions pures et classes testables sans navigateur. Config à part de vite.config.ts,
 * qui charge le plugin Symfony et la découverte des runtimes, inutiles ici.
 */
export default defineConfig({
	test: {
		include: ['tests/*.test.ts'],
	},
});
