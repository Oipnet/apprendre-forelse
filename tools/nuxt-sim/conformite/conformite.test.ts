/**
 * Tests de conformité : chaque cas de cas.ts part vers le vrai Nuxt (`nuxi dev` sur conformite/reference)
 * et vers le simulateur, chargé avec les mêmes fichiers ; les deux réponses doivent être identiques.
 *
 *     cd conformite/reference && npm ci    # une fois
 *     npm run conformite
 */
import { spawn, type ChildProcess } from 'node:child_process';
import { parse as devalueParse } from 'devalue';
import { existsSync, rmSync, symlinkSync } from 'node:fs';
import { createServer as createHttpServer, request as httpRequest, type Server } from 'node:http';
import { createServer } from 'node:net';
import { join } from 'node:path';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { NuxtSimulator } from '../src/index.ts';
import { loadProject } from '../src/node/index.ts';
import { createE2E } from '../src/testing/e2e.ts';
import { CAS, CAS_FETCH, type Cas } from './cas.ts';

const REFERENCE = join(import.meta.dirname, 'reference');
/** Variables d'environnement des deux serveurs ; l'adresse de l'API « amont » est complétée au démarrage. */
const ENV: Record<string, string> = { NUXT_PUBLIC_NOM_DU_PORT: 'Le Guilvinec' };
/** En-têtes propres à la connexion ou à l'instant : ils ne disent rien du comportement de Nuxt. */
const IGNORED_HEADERS = new Set(['date', 'connection', 'keep-alive', 'transfer-encoding', 'vary']);

interface Observed {
	status: number;
	statusText: string;
	headers: Record<string, string>;
	body: unknown;
}

let nuxt: ChildProcess | undefined;
let port = 0;
let simulator: NuxtSimulator;
let amont: Server | undefined;

beforeAll(async () => {
	port = await freePort();
	const adresseAmont = await startUpstream();
	ENV.NUXT_AMONT = adresseAmont;
	ENV.NUXT_PUBLIC_AMONT = adresseAmont;
	// Le cache de Nitro vit sur le disque en développement (.nuxt/cache) : on repart d'un cache vide.
	rmSync(join(REFERENCE, '.nuxt/cache'), { recursive: true, force: true });
	nuxt = spawn(process.execPath, [join(REFERENCE, 'node_modules/nuxt/bin/nuxt.mjs'), 'dev', '--port', String(port), '--host', '127.0.0.1', '--no-fork'], {
		cwd: REFERENCE,
		env: { ...developerEnv(), ...ENV, NUXT_TELEMETRY_DISABLED: '1', CI: '1' },
		stdio: ['ignore', 'pipe', 'pipe'],
	});
	let output = '';
	nuxt.stdout?.on('data', (chunk) => (output += chunk));
	nuxt.stderr?.on('data', (chunk) => (output += chunk));
	await waitForServer(() => output);
	// Les appels sortants du simulateur partent vers la même API amont, par le vrai réseau.
	simulator = new NuxtSimulator(loadProject(REFERENCE), { env: ENV, network: (input, init) => fetch(input, init) });
}, 180_000);

afterAll(() => {
	nuxt?.kill('SIGTERM');
	amont?.close();
});

describe('conformité avec Nuxt', () => {
	for (const cas of CAS) {
		it(cas.nom, async () => {
			if (cas.attendre) await new Promise((resolve) => setTimeout(resolve, cas.attendre));
			const reel = await askNuxt(cas);
			const simule = await askSimulator(cas);
			expect(normalize(simule, cas)).toEqual(normalize(reel, cas));
		// Le premier rendu d'une page compile avec Vite côté nuxi dev : plusieurs secondes sur une machine chargée.
		}, 30_000);
	}
});

describe('conformité d’un projet sans app/pages (app.vue seul)', () => {
	const SANS_PAGES = join(import.meta.dirname, 'sans-pages');
	let serveur: ChildProcess | undefined;
	let portSansPages = 0;

	beforeAll(async () => {
		// Il emprunte le Nuxt installé dans reference/ (lien symbolique ignoré par git).
		if (!existsSync(join(SANS_PAGES, 'node_modules'))) symlinkSync('../reference/node_modules', join(SANS_PAGES, 'node_modules'));
		portSansPages = await freePort();
		serveur = spawn(process.execPath, [join(REFERENCE, 'node_modules/nuxt/bin/nuxt.mjs'), 'dev', '--port', String(portSansPages), '--host', '127.0.0.1', '--no-fork'], {
			cwd: SANS_PAGES,
			env: { ...developerEnv(), NUXT_TELEMETRY_DISABLED: '1', CI: '1' },
			stdio: 'ignore',
		});
		const deadline = Date.now() + 170_000;
		while (!(await askNuxt({ nom: 'démarrage', chemin: '/', entetes: { accept: 'text/html' } }, portSansPages).then((res) => res.status === 200, () => false))) {
			if (Date.now() > deadline) throw new Error('nuxi dev (sans pages) n’a pas démarré à temps');
			await new Promise((resolve) => setTimeout(resolve, 500));
		}
	}, 180_000);

	afterAll(() => {
		serveur?.kill('SIGTERM');
	});

	for (const chemin of ['/', '/nimporte/quoi?x=1']) {
		it(`app.vue répond à ${chemin}`, async () => {
			const cas: Cas = { nom: chemin, chemin, entetes: { accept: 'text/html' }, comparer: 'page' };
			const simulateur = new NuxtSimulator(loadProject(SANS_PAGES));
			const reel = await askNuxt(cas, portSansPages);
			const simule = await askSimulator(cas, simulateur, portSansPages);
			expect(normalize(simule, cas)).toEqual(normalize(reel, cas));
		});
	}
});

describe('conformité du $fetch des tests (@nuxt/test-utils/e2e)', () => {
	for (const cas of CAS_FETCH) {
		it(cas.nom, async () => {
			const { ofetch } = await import(join(REFERENCE, 'node_modules/ofetch/dist/index.mjs'));
			const reel = await outcome(() => ofetch(`http://127.0.0.1:${port}${cas.chemin}`, cas.options));
			const e2e = createE2E(new NuxtSimulator(loadProject(REFERENCE), { env: ENV })) as { $fetch: (path: string, options?: object) => Promise<unknown> };
			const simule = await outcome(() => e2e.$fetch(cas.chemin, cas.options));
			expect(simule).toEqual(reel);
		});
	}
});

/** Résultat d'un $fetch, sans ce qui dépend de l'adresse du serveur ni la pile d'appels. */
async function outcome(call: () => Promise<unknown>): Promise<unknown> {
	const origin = /http:\/\/127\.0\.0\.1:\d+/g;
	const clean = (value: unknown): unknown => {
		if (typeof value === 'string') {
			const text = value.replace(origin, 'http://serveur');
			return text.startsWith('<!DOCTYPE html>') ? text.replace(/<script\b[^>]*>[\s\S]*?<\/script>|<link\b[^>]*>/g, (tag) => (tag.includes('__NUXT') || tag.includes('data-nuxt') ? tag : '')) : text;
		}
		if (Array.isArray(value)) return value.map(clean);
		if (value && typeof value === 'object') {
			return Object.fromEntries(Object.entries(value).map(([key, item]) => [key, key === 'stack' ? '(pile)' : clean(item)]));
		}
		return value;
	};
	try {
		return { resultat: clean(await call()) };
	} catch (error) {
		const e = error as { name: string; message: string; statusCode?: number; statusMessage?: string; data?: unknown };
		return { erreur: clean({ name: e.name, message: e.message, statusCode: e.statusCode, statusMessage: e.statusMessage, data: e.data }) };
	}
}

function requestHeaders(cas: Cas, serverPort = port): Record<string, string> {
	const headers: Record<string, string> = { host: `127.0.0.1:${serverPort}`, ...cas.entetes };
	if (cas.corps !== undefined) {
		headers['content-length'] = String(Buffer.byteLength(cas.corps));
	}
	return headers;
}

function askNuxt(cas: Cas, serverPort = port): Promise<Observed> {
	return new Promise((resolve, reject) => {
		const req = httpRequest({ host: '127.0.0.1', port: serverPort, method: cas.methode ?? 'GET', path: cas.chemin, headers: requestHeaders(cas, serverPort) }, (res) => {
			const chunks: Buffer[] = [];
			res.on('data', (chunk) => chunks.push(chunk));
			res.on('end', () => {
				const headers: Record<string, string> = {};
				for (const [name, value] of Object.entries(res.headers)) {
					if (value !== undefined) headers[name] = Array.isArray(value) ? value.join(', ') : value;
				}
				resolve({ status: res.statusCode ?? 0, statusText: res.statusMessage ?? '', headers, body: Buffer.concat(chunks).toString('utf8') });
			});
		});
		req.on('error', reject);
		req.end(cas.corps);
	});
}

async function askSimulator(cas: Cas, target = simulator, serverPort = port): Promise<Observed> {
	const response = await target.request({ method: cas.methode ?? 'GET', url: cas.chemin, headers: requestHeaders(cas, serverPort), body: cas.corps });
	const headers: Record<string, string> = {};
	for (const [name, values] of Object.entries(response.headers)) {
		headers[name] = values.join(', ');
	}
	return { status: response.status, statusText: response.statusText, headers, body: new TextDecoder().decode(response.body) };
}

/**
 * Ce qui est comparé. Pour une erreur JSON, la pile d'appels ne peut pas être la même (chemins, numéros
 * de ligne de Nuxt) : on vérifie seulement qu'elle commence par le message, et la longueur du corps
 * n'est pas comparée.
 */
function normalize(observed: Observed, cas: Cas): Observed {
	const headers = Object.fromEntries(Object.entries(observed.headers).filter(([name]) => !IGNORED_HEADERS.has(name)));
	// L'instant du rendu, à la seconde : les deux serveurs ne répondent pas dans la même.
	if (headers['last-modified']) headers['last-modified'] = Number.isNaN(Date.parse(headers['last-modified'])) ? headers['last-modified'] : '(date valide)';
	if (cas.comparer === 'statut') {
		return { status: observed.status, statusText: observed.statusText, headers: { 'content-type': headers['content-type'] }, body: null };
	}
	if (cas.comparer === 'page') {
		delete headers['content-length'];
		const html = observed.body as string;
		const head = html.slice(0, html.indexOf('</head>')).replace(/<script\b[^>]*>[\s\S]*?<\/script>|<link\b[^>]*>/g, '');
		const body = html.slice(html.indexOf('</head>'))
			// Clés automatiques (useState, useAsyncData, callOnce, useFetch) : un hachage du chemin absolu du
			// fichier, propre à chaque machine.
			.replace(/\$s\$[\w-]{10}/g, '$s$(clé automatique)')
			.replace(/"\$f[0-9a-z]{6,16}"/g, '"$f(clé automatique)"')
			.replace(/"\$[\w-]{10}"/g, '"$(clé automatique)"')
			.replace(/(id="__NUXT_DATA__">)([\s\S]*?)(<\/script>)/, (_, open, payload, close) => `${open}${normalizePayload(payload)}${close}`)
			.replace(/(<script type="application\/json" data-nuxt-logs="nuxt-app">)([\s\S]*?)(<\/script>)/, (_, open, logs, close) => `${open}${normalizeLogs(logs)}${close}`)
			// Surcouche d'erreur de nuxi dev, ajoutée avant </body> d'une page d'erreur : le simulateur s'en passe.
			.replace(/(id="__NUXT_DATA__">[\s\S]*?<\/script>)[\s\S]*?(?=<\/body>)/, '$1');
		return { ...observed, headers, body: { head, body } };
	}
	// Chemins absolus du projet de référence (messages de vite-node) : le simulateur place le projet à la racine.
	let body: unknown = (observed.body as string).replaceAll(REFERENCE, '');
	if (headers['content-type']?.startsWith('application/json')) {
		try {
			const parsed = JSON.parse(body as string);
			if (parsed && Array.isArray(parsed.stack)) {
				const lignes = String(parsed.message).split('\n');
				parsed.stack = lignes.every((ligne, i) => parsed.stack[i] === ligne) ? '(pile commençant par le message)' : parsed.stack;
				delete headers['content-length'];
				body = parsed;
			}
		} catch {
			// Corps non JSON malgré l'en-tête : comparé tel quel.
		}
	}
	return { ...observed, headers, body };
}

/**
 * L'environnement d'un développeur qui lance `nuxi dev` : sans les variables posées par Vitest
 * (NODE_ENV=test, VITEST…), qui feraient passer Nuxt en mode test et changeraient la page servie.
 */
function developerEnv(): NodeJS.ProcessEnv {
	return Object.fromEntries(Object.entries(process.env).filter(([name]) => !/^(NODE_ENV|TEST|VITEST.*)$/.test(name)));
}

/**
 * Charge utile qui contient une erreur d'API : sa pile d'appels (chemins de Nuxt, numéros de ligne) ne
 * peut pas être la même, et change la numérotation de devalue. On compare alors la valeur relue, pile
 * remplacée ; sinon, le texte exact.
 */
function normalizePayload(serialized: string): string {
	if (!serialized.includes('"stack"')) return serialized;
	const tag = (name: string) => (value: unknown) => ({ [name]: value });
	const revivers = Object.fromEntries(['ShallowReactive', 'Reactive', 'ShallowRef', 'Ref', 'EmptyRef', 'EmptyShallowRef', 'NuxtError'].map((name) => [name, tag(name)]));
	const value = devalueParse(serialized.replaceAll('\\u002F', '/'), revivers);
	return JSON.stringify(value, (key, item) => {
		if (key === 'stack' && (Array.isArray(item) || typeof item === 'string')) return '(pile)';
		if (item instanceof Set) return { Set: [...item] };
		return item === undefined ? '(undefined)' : item;
	});
}

/**
 * Journal du serveur de dev : ni la date, ni la pile, ni les codes couleur, ni les messages propres à
 * Vite (nostics rappelle que import.meta.hot n'existe pas côté serveur).
 */
function normalizeLogs(serialized: string): string {
	const logs = devalueParse(serialized) as { type: string; level: number; args: unknown[] }[];
	return JSON.stringify(logs
		// Le premier argument seulement : le suivant est la pile des composants, qui dépend de l'implémentation.
		.map((log) => ({ type: log.type, level: log.level, message: typeof log.args[0] === 'string' ? withoutSources(log.args[0].replace(/\x1b\[[0-9;]*m/g, '')) : log.args[0] }))
		.filter((log) => !(typeof log.message === 'string' && log.message.startsWith('[nostics]'))));
}

/** Diagnostic de nostics : la ligne « ╰▶ sources: » désigne un fichier interne de Nuxt, le simulateur s'en passe. */
function withoutSources(message: string): string {
	const index = message.indexOf('\n╰▶ sources:');
	return index === -1 ? message : message.slice(0, index).replace(/\n├▶ fix:/, '\n╰▶ fix:');
}

/**
 * API « amont » que les deux serveurs appellent avec $fetch : une réponse, une panne, une lenteur, une
 * clé à fournir, et un compteur d'appels (remis à zéro par les routes qui comptent).
 */
async function startUpstream(): Promise<string> {
	const appels: Record<string, number> = {};
	amont = createHttpServer((req, res) => {
		const chemin = new URL(req.url ?? '/', 'http://amont').pathname.slice(1);
		const json = (statut: number, corps: unknown) => {
			res.writeHead(statut, { 'content-type': 'application/json' });
			res.end(JSON.stringify(corps));
		};
		if (chemin === 'appels') return json(200, appels);
		if (chemin === 'remise-a-zero') {
			for (const nom in appels) delete appels[nom];
			return json(200, { ok: true });
		}
		appels[chemin] = (appels[chemin] ?? 0) + 1;
		switch (chemin) {
			case 'meteo': return json(200, { temperature: 4.2, vent: 21.6 });
			case 'panne': return json(503, { erreur: 'station en maintenance' });
			case 'lent': return void setTimeout(() => json(200, { enfin: true }), 2000);
			case 'cle': return req.headers['x-api-key'] === 'cle-du-fichier-env' ? json(200, { niveau: 'jaune' }) : json(401, { erreur: 'Clé absente ou invalide' });
			default: return json(404, { erreur: 'inconnu' });
		}
	});
	const portAmont = await freePort();
	await new Promise<void>((resolve) => amont!.listen(portAmont, '127.0.0.1', resolve));
	return `http://127.0.0.1:${portAmont}`;
}

function freePort(): Promise<number> {
	return new Promise((resolve, reject) => {
		const server = createServer();
		server.listen(0, '127.0.0.1', () => {
			const address = server.address();
			server.close(() => (typeof address === 'object' && address ? resolve(address.port) : reject(new Error('port introuvable'))));
		});
	});
}

async function waitForServer(output: () => string): Promise<void> {
	const deadline = Date.now() + 170_000;
	while (Date.now() < deadline) {
		if (nuxt?.exitCode !== null) {
			throw new Error(`nuxi dev s'est arrêté :\n${output()}`);
		}
		const ready = await askNuxt({ nom: 'démarrage', chemin: '/sante' }).then((res) => res.status === 200, () => false);
		if (ready) return;
		await new Promise((resolve) => setTimeout(resolve, 500));
	}
	throw new Error(`nuxi dev n'a pas démarré à temps :\n${output()}`);
}
