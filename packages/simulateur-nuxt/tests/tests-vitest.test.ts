/** Les tests Vitest d'un projet, lancés par le simulateur (le runner lui-même est vérifié par la conformité). */
import { describe, expect, it } from 'vitest';
import { NuxtSimulator } from '../src/index.ts';

const PROJET = {
	'server/api/sentiers/[slug].get.ts': `export default defineEventHandler((event) => {
	const slug = getRouterParam(event, 'slug')
	if (slug !== 'lac-noir') throw createError({ statusCode: 404, statusMessage: 'Sentier inconnu' })
	return { slug, denivele: 450 }
})`,
	'app/pages/index.vue': '<template><h1>Refuge du Pic Tordu</h1></template>',
	'tests/refuge.test.ts': `import { describe, expect, it } from 'vitest'
import { $fetch, setup } from '@nuxt/test-utils/e2e'

await setup()

describe('le refuge', () => {
	it('affiche son nom', async () => {
		expect(await $fetch('/')).toContain('<h1>Refuge du Pic Tordu</h1>')
	})
	it('connaît le lac Noir', async () => {
		expect(await $fetch('/api/sentiers/lac-noir')).toEqual({ slug: 'lac-noir', denivele: 450 })
	})
	it('refuse un sentier inconnu', async () => {
		await expect($fetch('/api/sentiers/arete')).rejects.toMatchObject({ statusCode: 404 })
	})
})`,
};

describe('NuxtSimulator.runTests', () => {
	it('lance les tests du projet contre le simulateur', async () => {
		const result = await new NuxtSimulator(PROJET).runTests();
		expect(result.cases.map((c) => [c.className, c.name, c.status])).toEqual([
			['le refuge', 'affiche son nom', 'passed'],
			['le refuge', 'connaît le lac Noir', 'passed'],
			['le refuge', 'refuse un sentier inconnu', 'passed'],
		]);
		expect(result.exitCode).toBe(0);
		expect(result.output).toContain('3 passed');
	});

	it('signale un test qui échoue avec le message de Vitest', async () => {
		const simulator = new NuxtSimulator({ ...PROJET, 'app/pages/index.vue': '<template><h1>Refuge</h1></template>' });
		const result = await simulator.runTests();
		const echec = result.cases.find((c) => c.name === 'affiche son nom')!;
		expect(echec.status).toBe('failed');
		expect(echec.message).toMatch(/^AssertionError: expected '<!DOCTYPE html>/);
		expect(result.exitCode).toBe(1);
	});

	it('signale un fichier de test qui ne se charge pas', async () => {
		const result = await new NuxtSimulator({ 'tests/casse.test.ts': "import { it } from 'vitest'\nit('x', () => {}\n" }).runTests();
		expect(result.cases).toEqual([]);
		expect(result.exitCode).toBe(1);
		expect(result.output).toContain('FAIL  tests/casse.test.ts');
	});

	it('note les tests de l’apprenant par mutants', async () => {
		const grading = {
			ownTests: ['tests/refuge.test.ts'],
			mutants: [
				{ id: 'denivele', label: 'le dénivelé est faux', changes: [{ file: 'server/api/sentiers/[slug].get.ts', search: 'denivele: 450', replace: 'denivele: 540' }] },
				{ id: 'titre-css', label: 'une espace s’invite dans la balise', changes: [{ file: 'app/pages/index.vue', search: '<h1>', replace: '<h1 >' }] },
			],
		};
		const result = await new NuxtSimulator(PROJET).runTests(grading);
		const notation = Object.fromEntries(result.cases.filter((c) => c.className === 'Notation').map((c) => [c.name, c.status]));
		// Le second mutant ne change rien au HTML servi (Vue rend `<h1 >` en `<h1>`) : il survit.
		expect(notation).toEqual({ 'own-tests': 'passed', 'mutant:denivele': 'passed', 'mutant:titre-css': 'failed' });
	});

	it('ne donne pas les mutants quand les tests de l’apprenant échouent déjà', async () => {
		const grading = { ownTests: ['tests/refuge.test.ts'], mutants: [{ id: 'm', label: 'x', changes: [] }] };
		const result = await new NuxtSimulator({ ...PROJET, 'app/pages/index.vue': '<template><h1>Autre</h1></template>' }).runTests(grading);
		expect(result.cases.filter((c) => c.className === 'Notation').map((c) => c.status)).toEqual(['failed', 'failed']);
	});
});
