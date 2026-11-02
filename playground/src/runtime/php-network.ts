/// <reference lib="webworker" />
/**
 * Le réseau du PHP de l'aperçu, pour les parcours IA : ses requêtes HTTPS vers les API des fournisseurs (Anthropic,
 * OpenAI, Mistral, Gemini) deviennent des fetch() du worker.
 *
 * php-wasm sait le faire (« TCP over fetch », celui de WordPress Playground) : PHP ouvre une connexion TLS, le worker
 * la termine avec un certificat créé ici, dans l'onglet, relit la requête HTTP et la rejoue avec fetch(). Ce certificat
 * n'existe que dans ce worker : la machine de l'apprenant n'a rien à installer ni à croire.
 *
 * Ce module n'est chargé que par php-network-worker.ts. Trois barrières limitent le réseau aux API déclarées par
 * l'environnement (`network:` dans environment.yaml) : la CSP de ce worker (docker/Caddyfile), la liste vérifiée ici à
 * chaque fetch(), et l'instance de tests, qui n'a pas de réseau du tout.
 */
import { setPhpIniEntries, type PHP } from '@php-wasm/universal';
import { certificateToPEM, generateCertificate, type GeneratedCertificate, type TCPOverFetchOptions } from '@php-wasm/web';
import { m } from '../i18n';

export interface PhpNetwork {
	/** Les options de php-wasm qui donnent le réseau à une instance, vers ces hôtes seulement. */
	tcpOverFetch(hosts: string[]): Promise<TCPOverFetchOptions>;
	/** Fait croire à PHP (OpenSSL, curl) le certificat qui relit ses requêtes. */
	trust(php: PHP): Promise<void>;
}

const CA_PATH = '/internal/forelse-ca.pem';

/**
 * Anthropic refuse un appel venu d'un navigateur sans cet en-tête. Il est ajouté ici, pour que le code de l'apprenant
 * reste celui qu'il écrirait sur un serveur.
 */
const EXTRA_HEADERS: Record<string, Record<string, string>> = {
	'api.anthropic.com': { 'anthropic-dangerous-direct-browser-access': 'true' },
};

/**
 * Le fetch() du worker, restreint et sans corps en flux.
 *
 * php-wasm envoie le corps d'une requête en flux (`duplex: 'half'`) : Firefox et Safari ne le savent pas, et Chrome ne
 * le fait qu'en HTTP/2 (un proxy d'entreprise en HTTP/1.1 le casse). PHP annonce un Content-Length, le flux se ferme
 * donc de lui-même : on le lit en entier avant d'appeler le vrai fetch().
 */
export function restrictedFetch(nativeFetch: typeof fetch, allowed: () => ReadonlySet<string>, origin: string): typeof fetch {
	return async (input: RequestInfo | URL, init?: RequestInit) => {
		let request = new Request(input, init);
		const url = new URL(request.url);
		// Les téléchargements du worker lui-même (archive, binaire PHP) restent sur l'origine du site.
		if (url.origin === origin) return nativeFetch(request);
		if (url.protocol !== 'https:' || !allowed().has(url.hostname)) {
			throw new TypeError(m.runtime.networkRefused(url.hostname));
		}
		const headers = new Headers(request.headers);
		for (const [name, value] of Object.entries(EXTRA_HEADERS[url.hostname] ?? {})) headers.set(name, value);
		const body = request.body ? await request.arrayBuffer() : undefined;
		request = new Request(request.url, {
			method: request.method,
			headers,
			body,
			redirect: request.redirect,
			signal: request.signal,
			// Pas de cookie ni d'identifiant du site vers un fournisseur.
			credentials: 'omit',
		});
		return nativeFetch(request);
	};
}

/** Le réseau de php-network-worker.ts : un certificat par worker, créé au premier besoin. */
export function createPhpNetwork(scope: WorkerGlobalScope = self as unknown as WorkerGlobalScope): PhpNetwork {
	const allowed = new Set<string>();
	let ca: Promise<GeneratedCertificate> | undefined;
	const certificate = () =>
		(ca ??= generateCertificate({
			subject: { commonName: 'forelse-playground', organizationName: 'Forelse', countryName: 'FR' },
			basicConstraints: { ca: true },
		}));
	scope.fetch = restrictedFetch(scope.fetch.bind(scope), () => allowed, scope.location.origin);
	return {
		async tcpOverFetch(hosts) {
			for (const host of hosts) allowed.add(host);
			return { CAroot: await certificate() };
		},
		async trust(php) {
			php.writeFile(CA_PATH, certificateToPEM((await certificate()).certificate));
			await setPhpIniEntries(php, { 'openssl.cafile': CA_PATH, 'curl.cainfo': CA_PATH });
		},
	};
}
