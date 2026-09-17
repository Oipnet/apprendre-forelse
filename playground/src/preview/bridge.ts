import type { HttpRequest, HttpResponse, Runtime } from '../runtime/Runtime';
import type { HostToRelay, RelayToHost } from './protocol';

/**
 * Côté plateforme : pilote l'aperçu isolé sur l'origine bac à sable.
 *
 * Seuls les messages venant de l'iframe relais ET de l'origine bac à sable sont acceptés.
 * Même si le code de l'apprenant prend la main sur le relais (même origine que lui), il ne peut
 * que demander l'exécution de son propre PHP ou changer la barre d'adresse : aucune donnée
 * de la plateforme ne transite vers le bac à sable.
 */
export class PreviewBridge {
	/** Préfixe d'URL de l'aperçu, propre à ce relais (ex. /preview/k3j2…). */
	readonly base: string;
	private readonly relayId = Array.from(crypto.getRandomValues(new Uint8Array(8)), (b) => b.toString(16).padStart(2, '0')).join('');
	private ready?: Promise<void>;

	constructor(
		private readonly runtime: Runtime,
		private readonly frame: HTMLIFrameElement,
		private readonly sandboxUrl: string,
		private readonly callbacks: {
			onNavigated?: (path: string) => void;
			onResponse?: (request: HttpRequest, response: HttpResponse) => void;
			onConsole?: (level: 'warn' | 'error', message: string) => void;
		} = {},
	) {
		this.base = `/preview/${this.relayId}`;
	}

	private get sandboxOrigin() {
		return new URL(this.sandboxUrl).origin;
	}

	start(): Promise<void> {
		this.ready ??= new Promise((resolve, reject) => {
			window.addEventListener('message', async (event: MessageEvent<RelayToHost>) => {
				if (event.origin !== this.sandboxOrigin || event.source !== this.frame.contentWindow) return;
				const message = event.data;
				switch (message.type) {
					case 'ready':
						resolve();
						break;
					case 'error':
						reject(new Error(String(message.message)));
						break;
					case 'navigated':
						this.callbacks.onNavigated?.(String(message.path));
						break;
					case 'preview-console':
						this.callbacks.onConsole?.(message.level === 'error' ? 'error' : 'warn', String(message.message));
						break;
					case 'preview-request':
						await this.handle(message.request, event.ports[0]);
						break;
				}
			});
			const url = new URL(this.sandboxUrl);
			url.searchParams.set('id', this.relayId);
			this.frame.src = url.href;
		});
		return this.ready;
	}

	navigate(path: string) {
		this.frame.contentWindow?.postMessage({ type: 'navigate', path } satisfies HostToRelay, this.sandboxOrigin);
	}

	private async handle(request: HttpRequest, port: MessagePort | undefined) {
		if (!port) return;
		// Le chemin doit rester sous le préfixe de ce relais.
		if (typeof request?.url !== 'string' || !request.url.startsWith(this.base)) {
			port.postMessage({ ok: false, error: 'Requête d\'aperçu refusée.' });
			return;
		}
		try {
			const response = await this.runtime.request(request);
			this.callbacks.onResponse?.(request, response);
			port.postMessage({ ok: true, response });
		} catch (error) {
			port.postMessage({ ok: false, error: error instanceof Error ? error.message : String(error) });
		}
	}
}
