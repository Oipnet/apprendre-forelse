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
		// Une page de l'aperçu qui affiche du JSON (une route d'API ouverte dans la barre d'adresse) : indenté et
		// coloré, comme l'« impression élégante » du navigateur, mais cochée d'office. Le fetch() du code de la
		// page, lui, n'est pas une navigation : il reçoit la réponse telle quelle.
		if (request.mode === 'navigate' && !nullBody && isJson(responseHeaders.get('content-type'))) {
			const page = jsonPage(responseBody, status, responseHeaders);
			if (page) return page;
		}
		return new Response(nullBody ? null : responseBody, { status, headers: responseHeaders });
	} catch (error) {
		return textResponse(504, String(error));
	}
}

function textResponse(status, text) {
	return new Response(text, { status, headers: { 'content-type': 'text/plain; charset=utf-8' } });
}

/** application/json, et les types qui en dérivent (application/problem+json, application/ld+json…). */
function isJson(contentType) {
	const type = (contentType ?? '').split(';')[0].trim().toLowerCase();
	return type === 'application/json' || /^application\/[\w.-]+\+json$/.test(type);
}

const JSON_LABELS = {
	fr: { pretty: 'Impression élégante' },
	en: { pretty: 'Pretty-print' },
};

/**
 * La page HTML qui montre le JSON : la version indentée et colorée, et le texte brut derrière la case à cocher.
 * Sans script (la case agit en CSS, par :has) : rien ne s'exécute dans l'aperçu que le code de l'apprenant n'ait écrit.
 * Un JSON invalide reste tel quel (null) : l'apprenant doit voir ce que sa route renvoie vraiment.
 */
function jsonPage(body, status, headers) {
	const raw = new TextDecoder().decode(body);
	try {
		JSON.parse(raw);
	} catch {
		return null;
	}
	const labels = (self.navigator?.language ?? 'fr').toLowerCase().startsWith('fr') ? JSON_LABELS.fr : JSON_LABELS.en;
	const html = `<!doctype html>
<html><head><meta charset="utf-8"><meta name="color-scheme" content="light dark"><style>
:root{--fond:#fff;--texte:#1f2328;--cle:#8250df;--chaine:#0a3069;--nombre:#0550ae;--mot:#cf222e;--barre:#f6f8fa;--bord:#d0d7de}
@media (prefers-color-scheme:dark){:root{--fond:#0d1117;--texte:#e6edf3;--cle:#d2a8ff;--chaine:#a5d6ff;--nombre:#79c0ff;--mot:#ff7b72;--barre:#161b22;--bord:#30363d}}
html,body{margin:0;background:var(--fond);color:var(--texte)}
label{display:block;position:sticky;top:0;padding:6px 12px;background:var(--barre);border-bottom:1px solid var(--bord);font:13px system-ui,sans-serif;cursor:pointer;user-select:none}
pre{margin:0;padding:12px;font:13px/1.5 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;white-space:pre-wrap;overflow-wrap:anywhere}
label input{margin:0 6px 0 0;vertical-align:-2px}
body:has(#e:checked) .brut,body:has(#e:not(:checked)) .joli{display:none}
.k{color:var(--cle)}.s{color:var(--chaine)}.n{color:var(--nombre)}.m{color:var(--mot)}
</style></head><body><label><input type="checkbox" id="e" checked>${labels.pretty}</label>
<pre class="joli">${prettyJson(raw)}</pre><pre class="brut">${escapeHtml(raw)}</pre></body></html>`;
	const pageHeaders = new Headers(headers);
	pageHeaders.set('content-type', 'text/html; charset=utf-8');
	pageHeaders.delete('content-length');
	pageHeaders.delete('content-encoding');
	// La CSP éventuelle de l'application visait sa réponse JSON, pas cette page : elle bloquerait sa feuille de style.
	pageHeaders.delete('content-security-policy');
	return new Response(html, { status, headers: pageHeaders });
}

/**
 * Réindente le texte JSON lui-même, sans passer par JSON.parse : un identifiant au-delà de 2^53 ou une chaîne
 * échappée (é) s'affichent tels que la route les a écrits.
 */
function prettyJson(raw) {
	const tokens = raw.match(/"(?:[^"\\]|\\.)*"|-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?|true|false|null|[{}[\]:,]/g) ?? [];
	let out = '';
	let depth = 0;
	const newline = () => '\n' + '  '.repeat(depth);
	for (let i = 0; i < tokens.length; i++) {
		const token = tokens[i];
		const next = tokens[i + 1];
		if (token === '{' || token === '[') {
			const close = token === '{' ? '}' : ']';
			if (next === close) {
				out += token + close;
				i++;
			} else {
				depth++;
				out += token + newline();
			}
		} else if (token === '}' || token === ']') {
			depth--;
			out += newline() + token;
		} else if (token === ',') out += ',' + newline();
		else if (token === ':') out += ': ';
		else if (token.startsWith('"')) out += `<span class="${next === ':' ? 'k' : 's'}">${escapeHtml(token)}</span>`;
		else if (token === 'true' || token === 'false' || token === 'null') out += `<span class="m">${token}</span>`;
		else out += `<span class="n">${token}</span>`;
	}
	return out;
}

function escapeHtml(text) {
	return text.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);
}
