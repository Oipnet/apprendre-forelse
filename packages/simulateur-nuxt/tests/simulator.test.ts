/**
 * Ce que la conformité ne couvre pas : le fonctionnement propre du simulateur (fichiers modifiés,
 * imports entre fichiers, messages quand le projet de l'apprenant est incomplet).
 * Tout comportement « comme Nuxt » se vérifie dans conformite/, pas ici.
 */
import { describe, expect, it } from 'vitest';
import { NuxtSimulator } from '../src/index.ts';

async function get(simulator: NuxtSimulator, url: string, headers: Record<string, string> = {}) {
	const response = await simulator.request({ method: 'GET', url, headers: { host: 'localhost:3000', ...headers } });
	return { ...response, text: new TextDecoder().decode(response.body) };
}

describe('NuxtSimulator', () => {
	it('prend en compte un fichier modifié à la requête suivante', async () => {
		const simulator = new NuxtSimulator({ 'server/api/maree.get.ts': "export default defineEventHandler(() => 'basse')" });
		expect((await get(simulator, '/api/maree')).text).toBe('basse');

		simulator.writeFile('server/api/maree.get.ts', "export default defineEventHandler(() => 'haute')");
		expect((await get(simulator, '/api/maree')).text).toBe('haute');
	});

	it('oublie une route supprimée', async () => {
		const simulator = new NuxtSimulator({ 'server/api/maree.get.ts': "export default defineEventHandler(() => 'basse')" });
		await get(simulator, '/api/maree');

		simulator.deleteFile('server/api/maree.get.ts');
		expect((await get(simulator, '/api/maree', { accept: 'application/json' })).status).toBe(404);
	});

	it('résout un import relatif et un import depuis la racine (~~)', async () => {
		const simulator = new NuxtSimulator({
			'server/api/poissons.get.ts': "import { POISSONS } from '../data/poissons'\nimport { compter } from '~~/shared/compter'\nexport default defineEventHandler(() => ({ total: compter(POISSONS) }))",
			'server/data/poissons.ts': "export const POISSONS: string[] = ['bar', 'lieu']",
			'shared/compter.ts': 'export const compter = (liste: unknown[]) => liste.length',
		});
		expect(JSON.parse((await get(simulator, '/api/poissons')).text)).toEqual({ total: 2 });
	});

	it('accepte les imports explicites de h3', async () => {
		const simulator = new NuxtSimulator({
			'server/api/bonjour.get.ts': "import { defineEventHandler, getQuery } from 'h3'\nexport default defineEventHandler((event) => getQuery(event))",
		});
		expect(JSON.parse((await get(simulator, '/api/bonjour?nom=Gwen')).text)).toEqual({ nom: 'Gwen' });
	});

	it('répond 500 avec un message clair quand un paquet n’est pas fourni', async () => {
		const simulator = new NuxtSimulator({
			'server/api/date.get.ts': "import dayjs from 'dayjs'\nexport default defineEventHandler(() => dayjs())",
		});
		const response = await get(simulator, '/api/date', { accept: 'application/json' });
		expect(response.status).toBe(500);
		expect(JSON.parse(response.text).message).toContain('« dayjs »');
	});

	it('répond 500 quand le fichier n’exporte pas de gestionnaire', async () => {
		const simulator = new NuxtSimulator({ 'server/api/vide.get.ts': 'export const rien = 1' });
		expect((await get(simulator, '/api/vide', { accept: 'application/json' })).status).toBe(500);
	});

	it('lit runtimeConfig sans nuxt.config', async () => {
		const simulator = new NuxtSimulator(
			{ 'server/api/config.get.ts': 'export default defineEventHandler(() => useRuntimeConfig().public)' },
			{ env: { NUXT_PUBLIC_PORT: 'Concarneau' } },
		);
		// Sans valeur déclarée dans nuxt.config, Nitro n'applique pas la variable d'environnement.
		expect(JSON.parse((await get(simulator, '/api/config')).text)).toEqual({});
	});

	it('rend une page et sert son module navigateur avec les auto-imports réécrits', async () => {
		const simulator = new NuxtSimulator({
			'app/pages/index.vue': '<script setup lang="ts">\nconst total = ref<number>(3)\n</script>\n<template><p>{{ total }} bouquetins</p></template>',
		});
		const page = await get(simulator, '/', { accept: 'text/html' });
		expect(page.text).toContain('<p>3 bouquetins</p>');

		const module = await get(simulator, '/_nuxt/app/pages/index.vue');
		expect(module.headers['content-type']).toEqual(['text/javascript']);
		expect(module.text).toContain('import { ref } from "/_nuxt/@nuxt-sim/imports.js"');
		expect(module.text).not.toContain('<number>');
	});

	it('réécrit les imports de projet et de paquets vers des URL servies', async () => {
		const simulator = new NuxtSimulator({
			'app/pages/index.vue': '<script setup>\nimport { computed } from \'vue\'\nimport { altitude } from \'~/utils/altitude\'\nconst texte = computed(() => altitude)\n</script>\n<template><p>{{ texte }}</p></template>',
			'app/utils/altitude.ts': 'export const altitude: number = 2350',
		});
		const module = (await get(simulator, '/_nuxt/app/pages/index.vue')).text;
		expect(module).toMatch(/import \{ computed \} from ['"]\/_nuxt\/@nuxt-sim\/vue\.js['"]/);
		expect(module).toMatch(/from ['"]\/_nuxt\/app\/utils\/altitude\.ts['"]/);
		expect((await get(simulator, '/', { accept: 'text/html' })).text).toContain('<p>2350</p>');
	});

	it('refuse de servir le runtime navigateur sans module construit', async () => {
		const simulator = new NuxtSimulator({ 'app/app.vue': '<template><p>Refuge</p></template>' });
		expect((await get(simulator, '/_nuxt/@nuxt-sim/client.js', { accept: 'application/json' })).status).toBe(500);
	});

	it('sert tout sous app.baseURL : routes, pages, liens et modules', async () => {
		const simulator = new NuxtSimulator({
			'app/pages/index.vue': '<template><NuxtLink to="/sentiers">Sentiers</NuxtLink></template>',
			'app/pages/sentiers.vue': '<template><p>{{ useRoute().path }}</p></template>',
			'server/api/sante.get.ts': "export default defineEventHandler(() => 'ok')",
		}, { baseURL: '/preview/abc123/' });

		const accueil = (await get(simulator, '/preview/abc123/', { accept: 'text/html' })).text;
		expect(accueil).toContain('<a href="/preview/abc123/sentiers" class="">Sentiers</a>');
		expect(accueil).toContain('src="/preview/abc123/_nuxt/@nuxt-sim/entry.js"');
		expect(accueil).toContain('baseURL:"/preview/abc123/"');
		expect((await get(simulator, '/preview/abc123/sentiers', { accept: 'text/html' })).text).toContain('<p>/sentiers</p>');
		expect((await get(simulator, '/preview/abc123/api/sante')).text).toBe('ok');
		expect((await get(simulator, '/preview/abc123/_nuxt/@nuxt-sim/manifest.js')).text).toContain('import("/preview/abc123/_nuxt/app/pages/index.vue")');
		expect((await get(simulator, '/api/sante', { accept: 'application/json' })).status).toBe(404);
	});
});
