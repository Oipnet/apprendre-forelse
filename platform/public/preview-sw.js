/**
 * Service Worker de l'aperçu, enregistré sur l'origine BAC À SABLE (jamais sur la plateforme).
 *
 * Il intercepte les requêtes de l'iframe d'aperçu sous /preview/<relais>/… et les transmet
 * au relais (/sandbox?id=<relais>), qui les fait suivre à la page de la plateforme par
 * postMessage ; le worker PHP y exécute la requête, la réponse revient sur le MessagePort.
 *
 * Flux : aperçu → ce SW → relais (même origine) → plateforme (postMessage) → worker PHP.
 */
const PREFIX = '/preview/';
const TIMEOUT_MS = 30_000;

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('fetch', (event) => {
	const url = new URL(event.request.url);
	if (url.origin !== self.location.origin || !url.pathname.startsWith(PREFIX)) return;
	event.respondWith(forward(event.request, url));
});

/** Le relais porte son identifiant dans son URL : aucun état à conserver dans le SW. */
async function findRelay(relayId) {
	const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
	return windows.find((client) => {
		const url = new URL(client.url);
		return url.pathname === '/sandbox' && url.searchParams.get('id') === relayId;
	});
}

async function forward(request, url) {
	const relayId = url.pathname.slice(PREFIX.length).split('/')[0];
	const relay = await findRelay(relayId);
	if (!relay) return textResponse(503, 'Aperçu déconnecté : rechargez la page de l\'exercice.');

	const body = ['GET', 'HEAD'].includes(request.method) ? undefined : new Uint8Array(await request.arrayBuffer());
	// Host est un en-tête interdit ; Origin et Referer sont ajoutés par le navigateur APRÈS le
	// Service Worker. On les reconstitue : la protection CSRF de Symfony (sans état) s'en sert.
	const headers = { ...Object.fromEntries(request.headers), host: url.host };
	if (!['GET', 'HEAD'].includes(request.method)) headers.origin ??= self.location.origin;
	if (request.referrer && request.referrer.startsWith(self.location.origin)) headers.referer ??= request.referrer;
	const channel = new MessageChannel();
	const reply = new Promise((resolve, reject) => {
		const timer = setTimeout(() => reject(new Error('PHP ne répond pas (délai dépassé)')), TIMEOUT_MS);
		channel.port1.onmessage = (event) => {
			clearTimeout(timer);
			resolve(event.data);
		};
	});
	relay.postMessage(
		{
			type: 'preview-request',
			request: {
				method: request.method,
				url: url.pathname + url.search,
				headers,
				body,
			},
		},
		[channel.port2],
	);

	try {
		const result = await reply;
		if (!result.ok) return textResponse(500, result.error);
		const { status, headers, body: responseBody } = result.response;
		const responseHeaders = new Headers();
		for (const [name, values] of Object.entries(headers)) {
			// Les cookies sont gérés côté worker (Set-Cookie est interdit dans une Response construite).
			if (name.toLowerCase() === 'set-cookie') continue;
			for (const value of values) responseHeaders.append(name, value);
		}
		const nullBody = [101, 204, 205, 304].includes(status);
		return new Response(nullBody ? null : responseBody, { status, headers: responseHeaders });
	} catch (error) {
		return textResponse(504, String(error));
	}
}

function textResponse(status, text) {
	return new Response(text, { status, headers: { 'content-type': 'text/plain; charset=utf-8' } });
}
