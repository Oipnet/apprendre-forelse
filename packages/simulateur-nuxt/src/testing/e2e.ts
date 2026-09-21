/**
 * `@nuxt/test-utils/e2e` vu par les tests d'un exercice. Dans Nuxt, `setup()` démarre un serveur et
 * `$fetch` est ofetch pointé dessus ; ici, le serveur est le simulateur, et ofetch (le vrai) reçoit un
 * `fetch` qui lui répond, en envoyant les en-têtes que met le fetch de Node (undici) : c'est ce qui décide,
 * entre autres, qu'une erreur est rendue en JSON plutôt qu'en page HTML.
 */
import { createFetch } from 'ofetch';
import type { HttpResponse } from '../http.ts';

export interface SimulatedServer {
	request(request: { method: string; url: string; headers: Record<string, string>; body?: Uint8Array }): Promise<HttpResponse>;
	/** Module `bac-a-sable` des tests : réseau enregistré et horloge du serveur. */
	sandbox?(): object;
}

/** Adresse du serveur de test ; @nuxt/test-utils prend 127.0.0.1 et un port libre. */
const ORIGIN = 'http://127.0.0.1:3000';

/** En-têtes ajoutés par le fetch de Node (undici) quand la requête ne les précise pas. */
const UNDICI_DEFAULTS: Record<string, string> = {
	accept: '*/*',
	'accept-language': '*',
	'sec-fetch-mode': 'cors',
	'user-agent': 'node',
	'accept-encoding': 'gzip, deflate',
};

const NULL_BODY_STATUSES = new Set([101, 204, 205, 304]);
const REDIRECT_STATUSES = new Set([301, 302, 303, 307, 308]);

export function simulatedFetch(server: SimulatedServer): typeof fetch {
	return async (input, init) => {
		const request = new Request(input, init);
		let url = new URL(request.url);
		let method = request.method;
		let buffer = request.body ? new Uint8Array(await request.arrayBuffer()) : undefined;
		const requestHeaders: Record<string, string> = {};
		request.headers.forEach((value, name) => (requestHeaders[name] = value));
		// Comme le fetch de Node : une redirection est suivie (20 au plus), sauf avec `redirect: 'manual'`.
		for (let redirects = 0; ; redirects++) {
			const headers: Record<string, string> = { host: url.host, ...UNDICI_DEFAULTS, ...requestHeaders };
			if (buffer) headers['content-length'] = String(buffer.byteLength);
			const response = await server.request({ method, url: url.pathname + url.search, headers, body: buffer });
			const location = response.headers.location?.[0];
			if (REDIRECT_STATUSES.has(response.status) && location && request.redirect !== 'manual') {
				if (request.redirect === 'error') throw new TypeError('fetch failed', { cause: new Error('unexpected redirect') });
				if (redirects >= 20) throw new TypeError('fetch failed', { cause: new Error('redirect count exceeded') });
				if (response.status === 303 || ((response.status === 301 || response.status === 302) && method === 'POST')) {
					method = 'GET';
					buffer = undefined;
					for (const name of ['content-type', 'content-length', 'content-encoding', 'content-language', 'content-location']) delete requestHeaders[name];
				}
				url = new URL(location, url);
				continue;
			}
			const nullBody = NULL_BODY_STATUSES.has(response.status) || method === 'HEAD';
			return new Response(nullBody ? null : (response.body as BodyInit), {
				status: response.status,
				statusText: response.statusText,
				headers: Object.entries(response.headers).flatMap(([name, values]) => values.map((value) => [name, value] as [string, string])),
			});
		}
	};
}

export function url(path: string): string {
	return path.startsWith(ORIGIN) ? path : `${ORIGIN}/${path.replace(/^\/+/, '')}`;
}

/** Ce que `import { … } from '@nuxt/test-utils/e2e'` renvoie. */
export function createE2E(server: SimulatedServer): Record<string, unknown> {
	const fetch = simulatedFetch(server);
	const ofetch = createFetch({ fetch });
	return {
		/** Le serveur est déjà là : les options (rootDir, dev, browser…) sont sans effet. */
		setup: async () => {},
		$fetch: (path: string, options?: object) => ofetch(url(path), options as never),
		fetch: (path: string, options?: RequestInit) => fetch(url(path), options),
		url,
		isDev: () => true,
		createPage: () => {
			throw new Error('createPage : pas de navigateur piloté dans le simulateur (Playwright).');
		},
	};
}
