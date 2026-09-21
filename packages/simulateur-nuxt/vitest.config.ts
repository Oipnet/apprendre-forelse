import { defineConfig } from 'vitest/config';

export default defineConfig({
	test: {
		// Le projet de référence a ses propres tests (conformite/reference/test/), lancés par le vrai Vitest
		// depuis conformite/environnement-nuxt.test.ts : ils ne sont pas des tests du simulateur.
		include: ['tests/*.test.ts', 'conformite/*.test.ts'],
	},
});
