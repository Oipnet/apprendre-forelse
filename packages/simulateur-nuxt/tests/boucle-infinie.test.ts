/**
 * Une boucle infinie dans le code de l'apprenant bloque le worker : WorkerRuntime doit le voir, faire
 * échouer les appels en cours avec un message clair, et repartir sur un worker neuf avec les fichiers.
 *
 * Le worker est simulé : une route « /boucle » ne répond jamais, ni à la requête ni au battement de
 * cœur, comme un fil bloqué par un `while (true)`.
 */
import { describe, expect, it } from 'vitest';
import { PING, WorkerRuntime, type EnvironmentSpec, type RuntimeRestart, type WorkerCall, type WorkerMessage } from '@forelse/runtime-contract';

const ENV: EnvironmentSpec = { id: 'nuxt', framework: {} as never, phpVersion: '8.4', archiveUrl: 'https://exemple/projet.zip', previewBasePath: '/apercu', previewSecure: true };

/** Un worker de test : répond de façon asynchrone, sauf quand il est « bloqué ». */
class FauxWorker extends EventTarget {
	static crees: FauxWorker[] = [];
	readonly fichiers = new Map<string, string>();
	bloque = false;
	arrete = false;
	demarre = false;

	constructor() {
		super();
		FauxWorker.crees.push(this);
	}

	postMessage(data: WorkerCall | typeof PING) {
		if (this.arrete) throw new Error('worker arrêté');
		setTimeout(() => {
			if (this.bloque || this.arrete) return;
			if (data === PING) return this.repondre({ type: 'pong' });
			const { id, method, args } = data;
			const resultat = (result: unknown) => this.repondre({ type: 'result', id, result });
			if (method === 'boot') return (this.demarre = true), resultat(undefined);
			if (method === 'writeFile') return this.fichiers.set(args[0] as string, args[1] as string), resultat(undefined);
			if (method === 'deleteFile') return this.fichiers.delete(args[0] as string), resultat(undefined);
			if (method === 'readFile') return resultat(this.fichiers.get(args[0] as string) ?? null);
			if (method === 'request' && (args[0] as { url: string }).url.endsWith('/boucle')) return void (this.bloque = true);
			// Occupé mais vivant : la réponse arrive tard, les battements de cœur tout de suite.
			if (method === 'request' && (args[0] as { url: string }).url.endsWith('/lent')) return void setTimeout(() => resultat({ status: 200, headers: {}, body: new Uint8Array(), durationMs: 400 }), 400);
			if (method === 'runCommand') return resultat({ exitCode: 0, output: '', durationMs: 1, fichiers: { 'migrations/V1.php': '<?php // v1' } });
			resultat({ status: 200, headers: {}, body: new Uint8Array(), durationMs: 1 });
		}, 5);
	}

	terminate() {
		this.arrete = true;
	}

	private repondre(message: WorkerMessage) {
		this.dispatchEvent(new MessageEvent('message', { data: message }));
	}
}

function runtime() {
	FauxWorker.crees = [];
	const events: RuntimeRestart[] = [];
	const r = new WorkerRuntime('inutile', 'test', { label: 'Le simulateur', silenceMs: 150, pingMs: 20, spawn: () => new FauxWorker() as unknown as Worker });
	r.onRestart((e) => events.push(e));
	return { r, events };
}

const requete = (url: string) => ({ method: 'GET', url, headers: {} });

describe('WorkerRuntime face à une boucle infinie', () => {
	it('fait échouer l\'appel bloqué avec un message qui parle de boucle infinie', async () => {
		const { r, events } = runtime();
		await r.boot(ENV);
		await expect(r.request(requete('/apercu/boucle'))).rejects.toThrow(/ne répond plus.*boucle infinie/);
		expect(FauxWorker.crees[0].arrete).toBe(true);
		expect(events[0]).toMatchObject({ phase: 'restarting' });
	});

	it('redémarre un worker neuf, avec les fichiers écrits, supprimés et générés', async () => {
		const { r, events } = runtime();
		await r.boot(ENV);
		await r.writeFile('server/api/menu.ts', 'while (true) {}');
		await r.writeFile('server/api/brouillon.ts', 'x');
		await r.deleteFile('server/api/brouillon.ts');
		await r.runCommand(['make:migration']);

		await r.request(requete('/apercu/boucle')).catch(() => {});
		// Un appel fait pendant le redémarrage attend le worker neuf, au lieu d'échouer.
		expect(await r.readFile('server/api/menu.ts')).toBe('while (true) {}');

		const neuf = FauxWorker.crees[1];
		expect(neuf.demarre).toBe(true);
		expect(Object.fromEntries(neuf.fichiers)).toEqual({ 'server/api/menu.ts': 'while (true) {}', 'migrations/V1.php': '<?php // v1' });
		expect(events.map((e) => e.phase)).toEqual(['restarting', 'restarted']);

		// Et le nouveau worker est surveillé à son tour.
		await expect(r.request(requete('/apercu/boucle'))).rejects.toThrow(/boucle infinie/);
		expect(FauxWorker.crees).toHaveLength(3);
	});

	it('ne tue pas un worker lent mais vivant', async () => {
		const { r, events } = runtime();
		await r.boot(ENV);
		// 400 ms d'attente, bien plus que le délai de silence (150 ms) : le worker répond aux battements.
		expect((await r.request(requete('/apercu/lent'))).status).toBe(200);
		expect(events).toEqual([]);
		expect(FauxWorker.crees).toHaveLength(1);
	});
});
