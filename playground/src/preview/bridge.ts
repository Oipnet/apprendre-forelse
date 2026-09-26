import type { HttpRequest, HttpResponse, Runtime } from '@forelse/runtime-contract';
import type { HostToRelay, RelayToHost } from './protocol';

/** Au-delà du délai d'activation du Service Worker dans le relais (10 s), pour que son message arrive en premier. */
const RELAY_TIMEOUT_MS = 20_000;
/** Battement de cœur : un ping au relais toutes les HEARTBEAT_MS, sans réponse depuis SILENCE_MS, il est gelé. */
export const HEARTBEAT_MS = 2_000;
export const SILENCE_MS = 8_000;

/**
 * Côté plateforme : pilote l'aperçu isolé sur l'origine bac à sable.
 *
 * Seuls les messages venant de l'iframe relais ET de l'origine bac à sable sont acceptés.
 * Même si le code de l'apprenant prend la main sur le relais (même origine que lui), il ne peut
 * que demander l'exécution de son propre PHP ou changer la barre d'adresse : aucune donnée
 * de la plateforme ne transite vers le bac à sable.
 *
 * Le code client de l'apprenant (Nuxt après hydratation, un script de page) tourne dans l'aperçu, sur le fil du
 * relais : un `while (true)` gèle les deux, hors de portée de la surveillance des workers. Un battement de cœur
 * le voit ; l'iframe du relais est alors retirée et recréée (la retirer libère la page bloquée, ce qu'une
 * navigation ne garantit pas), sans recharger la page qui a bouclé : elle retomberait dans la boucle.
 */
export class PreviewBridge {
	/** Préfixe d'URL de l'aperçu, propre à ce relais (ex. /preview/k3j2…). */
	readonly base: string;
	private readonly relayId = Array.from(crypto.getRandomValues(new Uint8Array(8)), (b) => b.toString(16).padStart(2, '0')).join('');
	private ready?: Promise<void>;
	/** Le relais en cours de chargement attend son « ready » : le premier, ou celui d'un relais recréé. */
	private onReady?: { resolve: () => void; reject: (error: Error) => void; started: boolean };
	private listening = false;
	private heartbeat?: ReturnType<typeof setInterval>;
	private seq = 0;
	/** Le ping resté sans réponse, et depuis quand ; aucun : le relais a répondu au dernier. */
	private unanswered?: { seq: number; since: number };

	constructor(
		private readonly runtime: Runtime,
		private frame: HTMLIFrameElement,
		private readonly sandboxUrl: string,
		private readonly callbacks: {
			onNavigated?: (path: string) => void;
			onResponse?: (request: HttpRequest, response: HttpResponse) => void;
			onConsole?: (level: 'warn' | 'error', message: string) => void;
			/** Le relais ne répond plus (boucle infinie dans le code de la page ?) : il va être recréé. */
			onFrozen?: () => void;
			/** Le relais recréé est prêt : l'aperçu peut de nouveau naviguer. */
			onRecovered?: () => void;
		} = {},
	) {
		this.base = `/preview/${this.relayId}`;
	}

	private get sandboxOrigin() {
		return new URL(this.sandboxUrl).origin;
	}

	start(): Promise<void> {
		this.ready ??= new Promise<void>((resolve, reject) => {
			// Le relais annonce « ready » ou « error » ; s'il ne se charge pas du tout (origine du bac à sable
			// injoignable), rien n'arrive : on le dit plutôt que d'attendre.
			const timer = setTimeout(() => {
				reject(new Error(`L'aperçu ne répond pas (${this.sandboxOrigin}) : rechargez la page.`));
			}, RELAY_TIMEOUT_MS);
			this.onReady = {
				started: false,
				resolve: () => {
					clearTimeout(timer);
					resolve();
				},
				reject: (error) => {
					clearTimeout(timer);
					reject(error);
				},
			};
			this.listen();
			const url = new URL(this.sandboxUrl);
			url.searchParams.set('id', this.relayId);
			this.frame.src = url.href;
		});
		return this.ready;
	}

	/** Un seul écouteur pour la vie du bridge : il sert aussi le relais recréé (this.frame change). */
	private listen() {
		if (this.listening) return;
		this.listening = true;
		window.addEventListener('message', async (event: MessageEvent<RelayToHost>) => {
			if (event.origin !== this.sandboxOrigin || event.source !== this.frame.contentWindow) return;
			const message = event.data;
			switch (message.type) {
				case 'ready':
					if (this.onReady && !this.onReady.started) {
						this.onReady.started = true;
						this.onReady.resolve();
						this.startHeartbeat();
					}
					break;
				case 'error':
					// Après le démarrage, rejeter ne ferait plus rien : l'erreur irait se perdre.
					if (this.onReady && !this.onReady.started) this.onReady.reject(new Error(String(message.message)));
					else this.callbacks.onConsole?.('error', `Relais de l'aperçu : ${String(message.message)}`);
					break;
				case 'pong':
					if (message.seq === this.unanswered?.seq) this.unanswered = undefined;
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
	}

	private startHeartbeat() {
		clearInterval(this.heartbeat);
		this.unanswered = undefined;
		let lastTick = Date.now();
		this.heartbeat = setInterval(() => {
			const now = Date.now();
			// Minuteur retardé (onglet en arrière-plan, machine en veille) : la réponse attendue n'a peut-être pas
			// encore été livrée. Le silence se recompte à partir de maintenant, plutôt que de conclure à une boucle.
			if (this.unanswered && now - lastTick > 2 * HEARTBEAT_MS) this.unanswered.since = now;
			lastTick = now;
			if (this.unanswered) {
				if (now - this.unanswered.since >= SILENCE_MS) this.frozen();
				return;
			}
			this.unanswered = { seq: ++this.seq, since: now };
			this.frame.contentWindow?.postMessage({ type: 'ping', seq: this.seq } satisfies HostToRelay, this.sandboxOrigin);
		}, HEARTBEAT_MS);
	}

	/** Relais muet : l'iframe est remplacée par une neuve, qui recharge le relais (même identifiant, même base). */
	private frozen() {
		clearInterval(this.heartbeat);
		this.unanswered = undefined;
		this.callbacks.onFrozen?.();
		const fresh = this.frame.cloneNode(false) as HTMLIFrameElement;
		fresh.removeAttribute('src');
		this.frame.replaceWith(fresh);
		this.frame = fresh;
		this.ready = undefined;
		this.start().then(
			() => this.callbacks.onRecovered?.(),
			(error: unknown) => this.callbacks.onConsole?.('error', `Relais de l'aperçu : ${error instanceof Error ? error.message : String(error)}`),
		);
	}

	/** Une fois le relais prêt : juste après sa recréation, le message se perdrait dans une page qui se charge. */
	navigate(path: string) {
		void (this.ready ?? Promise.resolve()).then(() => {
			this.frame.contentWindow?.postMessage({ type: 'navigate', path } satisfies HostToRelay, this.sandboxOrigin);
		}, () => {});
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
			// L'aperçu n'en montre que la première ligne : la pile d'appels décrit le worker du playground, pas le
			// code de l'apprenant. Elle reste dans la console de la plateforme, pour qui la cherche.
			console.error('Requête d\'aperçu', request.method, request.url, error);
			port.postMessage({ ok: false, error: (error instanceof Error ? error.message : String(error)).split('\n')[0] });
		}
	}
}
