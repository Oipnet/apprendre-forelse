/**
 * Le bac à sable n'a pas Internet : les appels sortants reçoivent les réponses de reseau.json, que les
 * tests d'un exercice peuvent remplacer (panne, lenteur, coupure) et compter, avec l'horloge du serveur.
 */
import { describe, expect, it } from 'vitest';
import { NuxtSimulator } from '../src/index.ts';

const METEO = 'https://api.open-meteo.com/v1/forecast';

async function get(simulator: NuxtSimulator, url: string) {
	const response = await simulator.request({ method: 'GET', url, headers: { host: 'localhost:3000', accept: 'application/json' } });
	return { status: response.status, headers: response.headers, json: JSON.parse(new TextDecoder().decode(response.body) || 'null') };
}

function projet(route: string, extra: Record<string, string> = {}): NuxtSimulator {
	return new NuxtSimulator({
		'reseau.json': JSON.stringify({
			[METEO]: { corps: { current: { temperature_2m: 4.2 } } },
			'https://vigilance.grand-bouc.example/v1/bulletin': [
				{ entetes: { 'x-api-key': 'cabane-4271' }, reponse: { corps: { niveau: 'jaune' } } },
				{ reponse: { statut: 401, corps: { erreur: 'Clé absente ou invalide' } } },
			],
		}),
		'server/api/test.get.ts': route,
		...extra,
	});
}

describe('réseau du bac à sable', () => {
	it('répond avec la réponse enregistrée, query comprise dans l’appel', async () => {
		const simulator = projet(`export default defineEventHandler(() => $fetch('${METEO}', { query: { latitude: 44.93 } }))`);
		expect((await get(simulator, '/api/test')).json).toEqual({ current: { temperature_2m: 4.2 } });
		expect(simulator.sandbox().reseau.requetes(METEO)[0].url).toBe(`${METEO}?latitude=44.93`);
	});

	it('choisit la variante dont les en-têtes correspondent', async () => {
		const route = (cle: string) => `export default defineEventHandler(() => $fetch('https://vigilance.grand-bouc.example/v1/bulletin', { headers: { 'x-api-key': '${cle}' } }).catch((e) => ({ statut: e.statusCode })))`;
		expect((await get(projet(route('cabane-4271')), '/api/test')).json).toEqual({ niveau: 'jaune' });
		expect((await get(projet(route('mauvaise')), '/api/test')).json).toEqual({ statut: 401 });
	});

	it('explique qu’une URL sans réponse enregistrée n’a pas de réseau', async () => {
		const simulator = projet("export default defineEventHandler(() => $fetch('https://example.com/rien').catch((e) => ({ message: e.message, cause: e.cause?.cause?.message })))");
		const { json } = await get(simulator, '/api/test');
		expect(json.message).toBe('[GET] "https://example.com/rien": <no response> fetch failed');
		expect(json.cause).toContain('aucune réponse enregistrée pour https://example.com/rien dans reseau.json');
	});

	it('simule une panne, compte les relances de ofetch, puis rétablit', async () => {
		const simulator = projet(`export default defineEventHandler(() => $fetch('${METEO}').catch((e) => ({ statut: e.statusCode })))`);
		const { reseau } = simulator.sandbox();
		reseau.simuler(METEO, { statut: 503 });
		expect((await get(simulator, '/api/test')).json).toEqual({ statut: 503 });
		expect(reseau.appels(METEO)).toBe(2);
		reseau.retablir();
		expect((await get(simulator, '/api/test')).json).toEqual({ current: { temperature_2m: 4.2 } });
	});

	it('interrompt une réponse lente au bout du timeout', async () => {
		const simulator = projet(`export default defineEventHandler(() => $fetch('${METEO}', { timeout: 50 }).catch((e) => ({ message: e.message })))`);
		simulator.sandbox().reseau.simuler(METEO, { delai: 5000, corps: {} });
		const debut = Date.now();
		expect((await get(simulator, '/api/test')).json.message).toContain('aborted due to timeout');
		expect(Date.now() - debut).toBeLessThan(1000);
		// Comme avec un vrai serveur : la relance d'ofetch part avec le signal déjà interrompu, et n'arrive pas.
		expect(simulator.sandbox().reseau.appels(METEO)).toBe(1);
	});

	it('simule une coupure', async () => {
		const simulator = projet(`export default defineEventHandler(() => $fetch('${METEO}', { retry: 0 }).catch((e) => ({ message: e.message })))`);
		simulator.sandbox().reseau.simuler(METEO, { coupure: true });
		expect((await get(simulator, '/api/test')).json.message).toBe(`[GET] "${METEO}": <no response> fetch failed`);
	});

	it('avance l’horloge du cache de Nitro', async () => {
		const simulator = projet(`export default defineCachedEventHandler(() => $fetch('${METEO}'), { maxAge: 600 })`);
		const { reseau, horloge } = simulator.sandbox();
		await get(simulator, '/api/test');
		await get(simulator, '/api/test');
		expect(reseau.appels(METEO)).toBe(1);
		horloge.avancer(601);
		await get(simulator, '/api/test');
		expect(reseau.appels(METEO), 'expirée, la réponse est servie puis rafraîchie en arrière-plan').toBe(2);
	});

	it('lit le .env du projet sans écraser l’environnement', async () => {
		const route = 'export default defineEventHandler((event) => useRuntimeConfig(event))';
		const fichiers = { 'nuxt.config.ts': "export default defineNuxtConfig({ runtimeConfig: { cle: '', autre: '' } })", '.env': 'NUXT_CLE="du fichier" # commentaire\nNUXT_AUTRE=fichier\n' };
		const simulator = new NuxtSimulator({ 'server/api/test.get.ts': route, ...fichiers }, { env: { NUXT_AUTRE: 'environnement' } });
		const { json } = await get(simulator, '/api/test');
		expect([json.cle, json.autre]).toEqual(['du fichier', 'environnement']);
	});
});

describe('réseau du bac à sable, depuis l’application', () => {
	it('useFetch vers une URL absolue pendant le rendu reçoit la réponse enregistrée', async () => {
		const simulator = new NuxtSimulator({
			'reseau.json': JSON.stringify({ [METEO]: { corps: { current: { temperature_2m: 4.2 } } } }),
			'app/app.vue': `<script setup lang="ts">\nconst { data } = await useFetch('${METEO}')\nconst direct = await $fetch('${METEO}')\n</script>\n<template><p>{{ data?.current.temperature_2m }} · {{ direct.current.temperature_2m }}</p></template>`,
		});
		const response = await simulator.request({ method: 'GET', url: '/', headers: { host: 'localhost:3000' } });
		expect(new TextDecoder().decode(response.body)).toContain('<p>4.2 · 4.2</p>');
		expect(simulator.sandbox().reseau.appels(METEO)).toBe(2);
	});
});
