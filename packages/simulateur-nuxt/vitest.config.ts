import { defineConfig } from 'vitest/config';

export default defineConfig({
	// Le contrat des runtimes n'a ni dépendances ni lanceur de tests : son garde-fou de traduction
	// (../runtime-contract/tests/) tourne ici, avec ce Vitest, comme ses autres tests (serve-runtime, boucle-infinie).
	server: { fs: { allow: ['..'] } },
	test: {
		// Le projet de référence a ses propres tests (conformite/reference/test/), lancés par le vrai Vitest
		// depuis conformite/environnement-nuxt.test.ts : ils ne sont pas des tests du simulateur.
		include: ['tests/*.test.ts', 'conformite/*.test.ts', '../runtime-contract/tests/*.test.ts'],
	},
});
