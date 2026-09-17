/**
 * Réseau du bac à sable : le simulateur n'a pas Internet. Un `$fetch` vers une URL absolue reçoit la
 * réponse enregistrée dans `reseau.json`, à la racine du projet (un fichier que Nuxt ignore) :
 *
 *     {
 *       "https://api.open-meteo.com/v1/forecast": { "corps": { … } },
 *       "https://vigilance.grand-bouc.example/v1/bulletin": [
 *         { "entetes": { "x-api-key": "…" }, "reponse": { "corps": { … } } },
 *         { "reponse": { "statut": 401, "corps": { "erreur": "Clé absente ou invalide" } } }
 *       ]
 *     }
 *
 * Une URL (sans sa query) donne une réponse, ou une liste de variantes essayées dans l'ordre : la
 * première dont la méthode, la query et les en-têtes indiqués correspondent. Les tests d'un exercice
 * peuvent imposer une autre réponse (panne, lenteur, coupure) et compter les appels.
 */

export interface RecordedResponse {
	/** 200 par défaut. */
	statut?: number;
	/** Un objet ou un tableau part en JSON ; une chaîne en texte. */
	corps?: unknown;
	entetes?: Record<string, string>;
	/** Millisecondes avant la réponse (une requête avec `timeout` est interrompue avant). */
	delai?: number;
	/** Pas de réponse du tout, comme un serveur injoignable. */
	coupure?: boolean;
}

export interface RecordedVariant {
	methode?: string;
	query?: Record<string, string>;
	entetes?: Record<string, string>;
	reponse: RecordedResponse;
}

export type RecordedNetwork = Record<string, RecordedResponse | RecordedVariant[]>;

export interface NetworkCall {
	methode: string;
	url: string;
	entetes: Record<string, string>;
}

export const NETWORK_FILE = 'reseau.json';

export class SimulatedNetwork {
	readonly calls: NetworkCall[] = [];
	private readonly overrides = new Map<string, RecordedResponse>();

	/** `recorded` relit le fichier à chaque appel : l'apprenant peut le modifier sans redémarrer. */
	constructor(private readonly recorded: () => RecordedNetwork) {}

	/** Réponse imposée à une URL (sans query) jusqu'à `restore()`. */
	override(url: string, response: RecordedResponse): void {
		this.overrides.set(withoutQuery(url), response);
	}

	restore(): void {
		this.overrides.clear();
	}

	callsTo(url?: string): NetworkCall[] {
		return url === undefined ? [...this.calls] : this.calls.filter((call) => withoutQuery(call.url) === withoutQuery(url));
	}

	readonly fetch = async (input: string | URL | Request, init: RequestInit = {}): Promise<Response> => {
		// Comme fetch : une requête déjà interrompue (timeout d'ofetch dépassé avant une relance) ne part pas.
		if (init.signal?.aborted) {
			throw init.signal.reason;
		}
		const request = new Request(input, init);
		const url = new URL(request.url);
		const headers: Record<string, string> = {};
		request.headers.forEach((value, name) => (headers[name] = value));
		this.calls.push({ methode: request.method, url: url.href, entetes: headers });
		const key = `${url.origin}${url.pathname}`;
		const response = this.overrides.get(key) ?? this.match(key, request.method, url, headers);
		if (!response) {
			throw new TypeError('fetch failed', { cause: new Error(`Le bac à sable n'a pas accès à Internet : aucune réponse enregistrée pour ${key} dans ${NETWORK_FILE}.`) });
		}
		await wait(response.delai ?? 0, init.signal ?? undefined);
		if (response.coupure) {
			throw new TypeError('fetch failed', { cause: new Error(`Connexion interrompue : ${key} ne répond pas.`) });
		}
		const status = response.statut ?? 200;
		const json = response.corps !== undefined && typeof response.corps !== 'string';
		const responseHeaders = { ...(json ? { 'content-type': 'application/json' } : response.corps !== undefined ? { 'content-type': 'text/plain;charset=UTF-8' } : {}), ...response.entetes };
		const body = response.corps === undefined || [204, 304].includes(status) || request.method === 'HEAD' ? null : json ? JSON.stringify(response.corps) : String(response.corps);
		return new Response(body, { status, headers: responseHeaders });
	};

	private match(key: string, method: string, url: URL, headers: Record<string, string>): RecordedResponse | undefined {
		const entry = this.recorded()[key];
		if (!entry) return undefined;
		if (!Array.isArray(entry)) return entry;
		return entry.find((variant) => (!variant.methode || variant.methode.toUpperCase() === method)
			&& Object.entries(variant.query ?? {}).every(([name, value]) => url.searchParams.get(name) === value)
			&& Object.entries(variant.entetes ?? {}).every(([name, value]) => headers[name.toLowerCase()] === value))?.reponse;
	}
}

function withoutQuery(url: string): string {
	const parsed = new URL(url);
	return `${parsed.origin}${parsed.pathname}`;
}

function wait(ms: number, signal?: AbortSignal): Promise<void> {
	if (ms <= 0 && !signal?.aborted) return Promise.resolve();
	return new Promise((resolve, reject) => {
		if (signal?.aborted) return reject(signal.reason);
		const timer = setTimeout(() => {
			signal?.removeEventListener('abort', onAbort);
			resolve();
		}, ms);
		const onAbort = () => {
			clearTimeout(timer);
			reject(signal!.reason);
		};
		signal?.addEventListener('abort', onAbort, { once: true });
	});
}

/** Lit `reseau.json` ; un fichier absent ou mal formé ne répond à rien (l'erreur de JSON est levée). */
export function parseNetworkFile(source: string | undefined): RecordedNetwork {
	if (!source?.trim()) return {};
	try {
		return JSON.parse(source) as RecordedNetwork;
	} catch (error) {
		throw new SyntaxError(`${NETWORK_FILE} n'est pas du JSON valide : ${(error as Error).message}`);
	}
}
