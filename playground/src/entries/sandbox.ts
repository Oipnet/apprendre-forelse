/**
 * Relais du bac à sable (page /sandbox, servie sur SANDBOX_ORIGIN).
 *
 * - enregistre le Service Worker d'aperçu sur l'origine bac à sable ;
 * - héberge l'iframe d'aperçu (le code de l'apprenant) ;
 * - fait suivre les requêtes du SW à la plateforme, et seulement à elle (origine vérifiée).
 *
 * Le code de l'apprenant tourne ici, sans cookie ni accès à la plateforme.
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
	await activated(registration);
	toHost({ type: 'ready', base });
}

/** `navigator.serviceWorker.ready` ne résout pas ici : le relais est hors du scope /preview/. */
function activated(registration: ServiceWorkerRegistration): Promise<void> {
	if (registration.active) return Promise.resolve();
	const worker = registration.installing ?? registration.waiting;
	return new Promise((resolve, reject) => {
		worker?.addEventListener('statechange', () => {
			if (worker.state === 'activated') resolve();
			if (worker.state === 'redundant') reject(new Error('Service Worker de l\'aperçu rejeté.'));
		});
	});
}

start().catch((error) => toHost({ type: 'error', message: error instanceof Error ? error.message : String(error) }));
