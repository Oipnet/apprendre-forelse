/**
 * PreviewBridge côté plateforme : n'écoute que l'iframe relais de l'origine bac à sable, ne sert que les requêtes
 * sous son préfixe, et ne renvoie à l'aperçu que la première ligne d'une erreur. Pas de navigateur : la fenêtre est
 * un EventTarget, l'iframe un objet qui porte sa contentWindow, et les messages sont des événements « message ».
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { HttpRequest, HttpResponse, Runtime } from '@forelse/runtime-contract';
import { PreviewBridge } from '../src/preview/bridge.ts';

const SANDBOX = 'https://bac-a-sable.example';
const RESPONSE: HttpResponse = { status: 200, headers: { 'content-type': ['text/html'] }, body: new TextEncoder().encode('<h1>Menu</h1>'), durationMs: 3 };

/** Ce que la page et l'aperçu s'échangent, vu depuis la plateforme. */
class Scene {
	readonly relay = { postMessage: vi.fn() };
	readonly frame = { src: '', contentWindow: this.relay } as unknown as HTMLIFrameElement;
	readonly request = vi.fn<(request: HttpRequest) => Promise<HttpResponse>>(async () => RESPONSE);
	readonly bridge = new PreviewBridge({ request: this.request } as unknown as Runtime, this.frame, `${SANDBOX}/relais.html`, { onResponse: vi.fn(), onNavigated: vi.fn(), onConsole: vi.fn() });

	/** Un message reçu par la fenêtre de la plateforme. */
	receive(data: unknown, { origin = SANDBOX, source = this.relay as unknown, ports = [] as unknown[] } = {}): void {
		window.dispatchEvent(Object.assign(new Event('message'), { data, origin, source, ports }));
	}

	/** Une requête de l'aperçu, avec le port où répondre ; attend que la réponse y soit postée. */
	async ask(url: string, options: { origin?: string; source?: unknown } = {}): Promise<ReturnType<typeof vi.fn>> {
		const port = { postMessage: vi.fn() };
		this.receive({ type: 'preview-request', request: { method: 'GET', url, headers: {} } }, { ...options, ports: [port] });
		await vi.waitFor(() => expect(port.postMessage).toHaveBeenCalled(), { timeout: 200 }).catch(() => undefined);
		return port.postMessage;
	}
}

describe('PreviewBridge', () => {
	beforeEach(() => {
		vi.stubGlobal('window', new EventTarget());
	});

	afterEach(() => {
		vi.unstubAllGlobals();
		vi.restoreAllMocks();
	});

	it('charge le relais avec son identifiant, et démarre quand il annonce « ready »', async () => {
		const scene = new Scene();
		const started = scene.bridge.start();

		const src = new URL(scene.frame.src);
		expect(src.origin + src.pathname).toBe(`${SANDBOX}/relais.html`);
		expect(scene.bridge.base).toBe(`/preview/${src.searchParams.get('id')}`);

		scene.receive({ type: 'ready', base: scene.bridge.base });
		await expect(started).resolves.toBeUndefined();
	});

	it('sert une requête sous son préfixe et transmet la réponse', async () => {
		const scene = new Scene();
		void scene.bridge.start();

		const reply = await scene.ask(`${scene.bridge.base}/menu?jour=lundi`);

		expect(scene.request).toHaveBeenCalledWith({ method: 'GET', url: `${scene.bridge.base}/menu?jour=lundi`, headers: {} });
		expect(reply).toHaveBeenCalledWith({ ok: true, response: RESPONSE });
	});

	it('refuse une requête hors de son préfixe, sans la jouer', async () => {
		const scene = new Scene();
		void scene.bridge.start();

		const reply = await scene.ask('/preview/un-autre-relais/menu');

		expect(scene.request).not.toHaveBeenCalled();
		expect(reply).toHaveBeenCalledWith({ ok: false, error: 'Requête d\'aperçu refusée.' });
	});

	it('ignore les messages d\'une autre origine ou d\'une autre fenêtre', async () => {
		const scene = new Scene();
		void scene.bridge.start();

		const fromPlatform = await scene.ask(`${scene.bridge.base}/`, { origin: 'https://plateforme.example' });
		const fromElsewhere = await scene.ask(`${scene.bridge.base}/`, { source: { postMessage: vi.fn() } });

		expect(scene.request).not.toHaveBeenCalled();
		expect(fromPlatform).not.toHaveBeenCalled();
		expect(fromElsewhere).not.toHaveBeenCalled();
	});

	it('ne renvoie que la première ligne d\'une erreur du runtime', async () => {
		const scene = new Scene();
		vi.spyOn(console, 'error').mockImplementation(() => undefined);
		scene.request.mockRejectedValueOnce(new Error('Fatal error: Uncaught Exception\n#0 worker.ts(12)\n#1 {main}'));
		void scene.bridge.start();

		const reply = await scene.ask(`${scene.bridge.base}/`);

		expect(reply).toHaveBeenCalledWith({ ok: false, error: 'Fatal error: Uncaught Exception' });
		expect(console.error).toHaveBeenCalled();
	});
});
