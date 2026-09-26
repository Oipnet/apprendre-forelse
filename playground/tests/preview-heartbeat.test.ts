/**
 * Battement de cœur de l'aperçu. Le code client de l'apprenant tourne dans l'aperçu, sur le fil du relais : un
 * `while (true)` les gèle tous les deux. La plateforme le voit au silence du relais, retire son iframe et en
 * recrée une neuve (même identifiant, donc même préfixe d'URL), sans recharger la page qui a bouclé.
 *
 * Pas de navigateur : la fenêtre est un EventTarget, chaque iframe un objet qui porte sa contentWindow, et le temps
 * est simulé (vi.useFakeTimers).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Runtime } from '@forelse/runtime-contract';
import { HEARTBEAT_MS, PreviewBridge, SILENCE_MS } from '../src/preview/bridge.ts';

const SANDBOX = 'https://bac-a-sable.example';

/** Une iframe de relais : sa fenêtre note ce que la plateforme lui envoie ; répond aux pings tant qu'elle n'est pas gelée. */
class FauxRelais {
	readonly window = { postMessage: vi.fn() };
	readonly element = {
		src: '',
		contentWindow: this.window,
		removeAttribute: vi.fn(),
		cloneNode: vi.fn(() => {
			const neuf = new FauxRelais();
			this.clones.push(neuf);
			return neuf.element;
		}),
		replaceWith: vi.fn(),
	};
	readonly clones: FauxRelais[] = [];
	gele = false;

	constructor() {
		this.window.postMessage.mockImplementation((message: { type: string; seq?: number }) => {
			if (message.type === 'ping' && !this.gele) recevoir({ type: 'pong', seq: message.seq }, this.window);
		});
	}

	/** Les messages « navigate » reçus : une page rechargée. */
	navigations(): unknown[] {
		return this.window.postMessage.mock.calls.map(([message]) => message).filter((message) => message.type === 'navigate');
	}
}

function recevoir(data: unknown, source: unknown): void {
	window.dispatchEvent(Object.assign(new Event('message'), { data, origin: SANDBOX, source, ports: [] }));
}

describe('PreviewBridge : battement de cœur', () => {
	let relais: FauxRelais;
	let bridge: PreviewBridge;
	const onFrozen = vi.fn();
	const onRecovered = vi.fn();
	const onRestartFailed = vi.fn();

	beforeEach(async () => {
		vi.useFakeTimers();
		vi.stubGlobal('window', new EventTarget());
		onFrozen.mockReset();
		onRecovered.mockReset();
		onRestartFailed.mockReset();
		relais = new FauxRelais();
		bridge = new PreviewBridge({} as Runtime, relais.element as unknown as HTMLIFrameElement, `${SANDBOX}/relais.html`, { onFrozen, onRecovered, onRestartFailed });
		const pret = bridge.start();
		recevoir({ type: 'ready', base: bridge.base }, relais.window);
		await pret;
	});

	afterEach(() => {
		vi.useRealTimers();
		vi.unstubAllGlobals();
	});

	it('laisse en place un relais qui répond', async () => {
		await vi.advanceTimersByTimeAsync(10 * SILENCE_MS);

		expect(relais.window.postMessage).toHaveBeenCalledWith({ type: 'ping', seq: expect.any(Number) }, SANDBOX);
		expect(onFrozen).not.toHaveBeenCalled();
		expect(relais.element.replaceWith).not.toHaveBeenCalled();
	});

	it('recrée un relais muet, avec le même identifiant, sans recharger la page qui a bouclé', async () => {
		bridge.navigate('/menu');
		await vi.advanceTimersByTimeAsync(0);
		relais.gele = true;

		await vi.advanceTimersByTimeAsync(SILENCE_MS + 2 * HEARTBEAT_MS);

		expect(onFrozen).toHaveBeenCalledOnce();
		const [neuf] = relais.clones;
		expect(relais.element.replaceWith).toHaveBeenCalledWith(neuf.element);
		const src = new URL(neuf.element.src);
		expect(src.origin + src.pathname).toBe(`${SANDBOX}/relais.html`);
		expect(`/preview/${src.searchParams.get('id')}`, 'Même identifiant : le runtime et les requêtes gardent leur préfixe.').toBe(bridge.base);
		expect(neuf.navigations(), 'La page qui a bouclé n\'est pas rechargée.').toEqual([]);

		// Le relais recréé s'annonce : l'aperçu est de nouveau utilisable, et le battement reprend avec lui.
		recevoir({ type: 'ready', base: bridge.base }, neuf.window);
		await vi.advanceTimersByTimeAsync(0);
		expect(onRecovered).toHaveBeenCalledOnce();
		bridge.navigate('/menu');
		await vi.advanceTimersByTimeAsync(10 * SILENCE_MS);
		expect(neuf.navigations(), 'Rechargée à la demande, après correction.').toEqual([{ type: 'navigate', path: '/menu' }]);
		expect(onFrozen).toHaveBeenCalledOnce();
	});

	it('ignore les messages de l\'ancien relais une fois remplacé', async () => {
		relais.gele = true;
		await vi.advanceTimersByTimeAsync(SILENCE_MS + 2 * HEARTBEAT_MS);

		recevoir({ type: 'ready', base: bridge.base }, relais.window);
		await vi.advanceTimersByTimeAsync(0);

		expect(onRecovered).not.toHaveBeenCalled();
	});

	it('ne conclut pas à une boucle quand le minuteur a été suspendu (onglet en veille)', async () => {
		relais.gele = true;
		await vi.advanceTimersByTimeAsync(HEARTBEAT_MS); // un ping part, sans réponse pour l'instant
		// La machine dort une minute : l'horloge avance, le minuteur ne tourne pas.
		vi.setSystemTime(Date.now() + 60_000);
		relais.gele = false;
		await vi.advanceTimersByTimeAsync(HEARTBEAT_MS);

		expect(onFrozen).not.toHaveBeenCalled();
	});

	it('ne compte pas le silence quand l\'onglet est caché (boîte de dialogue laissée ouverte en partant)', async () => {
		vi.stubGlobal('document', { hidden: true });
		relais.gele = true;
		await vi.advanceTimersByTimeAsync(10 * SILENCE_MS);
		expect(onFrozen).not.toHaveBeenCalled();

		// De retour sous les yeux de l'apprenant, et toujours muet : le silence se compte à partir de là.
		vi.stubGlobal('document', { hidden: false });
		await vi.advanceTimersByTimeAsync(SILENCE_MS - HEARTBEAT_MS);
		expect(onFrozen).not.toHaveBeenCalled();
		await vi.advanceTimersByTimeAsync(3 * HEARTBEAT_MS);
		expect(onFrozen).toHaveBeenCalledOnce();
	});

	it('retente à la navigation suivante un relais recréé qui n\'a pas démarré', async () => {
		relais.gele = true;
		await vi.advanceTimersByTimeAsync(SILENCE_MS + 2 * HEARTBEAT_MS);
		const [neuf] = relais.clones;
		recevoir({ type: 'error', message: 'Service Worker de l\'aperçu rejeté.' }, neuf.window);
		await vi.advanceTimersByTimeAsync(0);
		expect(onRestartFailed).toHaveBeenCalledWith('Service Worker de l\'aperçu rejeté.');

		bridge.navigate('/menu'); // ⟳ : un nouveau relais, qui reçoit la navigation une fois prêt
		const [encore] = neuf.clones;
		expect(neuf.element.replaceWith).toHaveBeenCalledWith(encore.element);
		recevoir({ type: 'ready', base: bridge.base }, encore.window);
		await vi.advanceTimersByTimeAsync(0);
		expect(onRecovered).toHaveBeenCalledOnce();
		expect(encore.navigations()).toEqual([{ type: 'navigate', path: '/menu' }]);
	});
});
