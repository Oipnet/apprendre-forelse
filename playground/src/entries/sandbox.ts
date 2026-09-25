/**
 * Relais du bac à sable (page /sandbox, servie sur SANDBOX_ORIGIN).
 *
 * - enregistre le Service Worker d'aperçu sur l'origine bac à sable ;
 * - héberge l'iframe d'aperçu (le code de l'apprenant) ;
 * - fait suivre les requêtes du SW à la plateforme, et seulement à elle (origine vérifiée).
 *
 * Le HTML et le JavaScript de l'aperçu tournent ici, sans cookie ni accès à la plateforme. Les routes `server/`
 * d'un exercice Nuxt, elles, sont évaluées dans le worker du simulateur, sur l'origine de la plateforme : sa CSP
 * (connect-src, voir docker/Caddyfile) l'empêche d'en appeler les API.
 */
import type { RelayToHost, HostToRelay } from '../preview/protocol';

const params = new URLSearchParams(location.search);
const relayId = params.get('id') ?? '';
const hostOrigin = document.documentElement.dataset.hostOrigin ?? '';
const frame = document.querySelector<HTMLIFrameElement>('#preview')!;
const base = `/preview/${relayId}`;

const toHost = (message: RelayToHost, transfer: Transferable[] = []) => window.parent.postMessage(message, hostOrigin, transfer);

async function start() {
	if (!/^[a-z0-9]{8,32}$/.test(relayId) || window.parent === window) {
		document.body.textContent = 'Page réservée à l\'aperçu des exercices.';
		return;
	}

	// Firefox en navigation privée n'a pas de Service Worker : sans ce message, l'erreur serait un TypeError.
	if (!('serviceWorker' in navigator)) {
		throw new Error('Ce navigateur refuse les Service Workers ici (navigation privée ?) : l\'aperçu en a besoin.');
	}

	// Requêtes de l'aperçu : on transmet le MessagePort du SW directement à la plateforme.
	navigator.serviceWorker.addEventListener('message', (event) => {
		if (event.data?.type === 'preview-request') toHost({ type: 'preview-request', request: event.data.request }, [event.ports[0]]);
	});
	navigator.serviceWorker.startMessages();

	// La page de l'aperçu (même origine que le relais) signale ses avertissements : on les transmet tels quels.
	window.addEventListener('message', (event: MessageEvent<{ type?: string; level?: string; message?: unknown }>) => {
		if (event.source !== frame.contentWindow || event.origin !== location.origin || event.data?.type !== 'nuxt-sim:console') return;
		toHost({ type: 'preview-console', level: event.data.level === 'error' ? 'error' : 'warn', message: String(event.data.message).slice(0, 4000) });
	});

	window.addEventListener('message', (event: MessageEvent<HostToRelay>) => {
		if (event.origin !== hostOrigin || event.source !== window.parent) return;
		if (event.data.type === 'navigate') frame.src = base + (event.data.path.startsWith('/') ? event.data.path : `/${event.data.path}`);
	});

	frame.addEventListener('load', () => {
		try {
			const location = frame.contentWindow!.location;
			if (location.pathname.startsWith(base)) toHost({ type: 'navigated', path: location.pathname.slice(base.length) + location.search });
		} catch {
			// Page d'une autre origine (lien externe) : rien à signaler.
		}
	});

	const registration = await navigator.serviceWorker.register('/preview-sw.js', { scope: '/preview/' });
	// Service Workers bloqués par le navigateur (réglage, extension) : Chromium résout alors sans rien.
	if (!registration) throw new Error('Ce navigateur bloque les Service Workers : l\'aperçu en a besoin.');
	await activated(registration);
	toHost({ type: 'ready', base });
}

/** Délai laissé au Service Worker pour s'activer : il s'installe en une fraction de seconde. */
const ACTIVATION_TIMEOUT_MS = 10_000;

/**
 * `navigator.serviceWorker.ready` ne résout pas ici : le relais est hors du scope /preview/.
 *
 * On suit le worker qui s'installe, y compris s'il n'apparaît qu'après coup (`updatefound`) : sans worker
 * à suivre, l'attente ne finissait jamais, et l'écran de démarrage tournait sans rien dire.
 */
function activated(registration: ServiceWorkerRegistration): Promise<void> {
	if (registration.active) return Promise.resolve();
	return new Promise((resolve, reject) => {
		const timer = setTimeout(() => {
			reject(new Error(`Le Service Worker de l'aperçu ne s'est pas activé en ${ACTIVATION_TIMEOUT_MS / 1000} s : rechargez la page.`));
		}, ACTIVATION_TIMEOUT_MS);
		const follow = (worker: ServiceWorker | null) => {
			if (!worker) return;
			const check = () => {
				if (worker.state === 'activated') {
					clearTimeout(timer);
					resolve();
				} else if (worker.state === 'redundant') {
					clearTimeout(timer);
					reject(new Error('Service Worker de l\'aperçu rejeté.'));
				}
			};
			worker.addEventListener('statechange', check);
			check();
		};
		follow(registration.installing ?? registration.waiting);
		registration.addEventListener('updatefound', () => follow(registration.installing));
	});
}

start().catch((error) => toHost({ type: 'error', message: error instanceof Error ? error.message : String(error) }));
