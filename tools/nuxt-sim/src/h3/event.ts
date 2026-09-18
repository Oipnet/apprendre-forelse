/**
 * Événement h3 1.x sans serveur Node : `event.node.req` et `event.node.res` sont de petites imitations
 * de IncomingMessage et ServerResponse, juste ce que les fonctions h3 du simulateur utilisent.
 */

export type HeaderValue = string | number | string[];

export class SimulatedRequest {
	readonly headers: Record<string, string>;
	originalUrl?: string;
	/** Requête faite par le serveur à lui-même ($fetch local) : `__unenv__` dans Nitro. */
	internal?: boolean;
	/** Corps brut, absent pour une requête sans corps. */
	readonly rawBody?: Uint8Array;

	constructor(
		public method: string,
		public url: string,
		headers: Record<string, string>,
		body?: Uint8Array,
	) {
		this.headers = Object.fromEntries(Object.entries(headers).map(([name, value]) => [name.toLowerCase(), value]));
		this.rawBody = body;
	}
}

export class SimulatedResponse {
	statusCode = 200;
	statusMessage?: string;
	body?: Uint8Array | string;
	writableEnded = false;
	private readonly headers = new Map<string, { name: string; value: HeaderValue }>();

	setHeader(name: string, value: HeaderValue): this {
		this.headers.set(name.toLowerCase(), { name, value });
		return this;
	}

	getHeader(name: string): HeaderValue | undefined {
		return this.headers.get(name.toLowerCase())?.value;
	}

	getHeaders(): Record<string, HeaderValue> {
		return Object.fromEntries([...this.headers.entries()].map(([key, { value }]) => [key, value]));
	}

	hasHeader(name: string): boolean {
		return this.headers.has(name.toLowerCase());
	}

	removeHeader(name: string): void {
		this.headers.delete(name.toLowerCase());
	}

	writeHead(statusCode: number): this {
		this.statusCode = statusCode;
		return this;
	}

	end(body?: Uint8Array | string): this {
		if (!this.writableEnded) {
			this.body = body;
			this.writableEnded = true;
		}
		return this;
	}
}

export class H3Event {
	readonly __is_event__ = true;
	readonly node: { req: SimulatedRequest; res: SimulatedResponse };
	context: Record<string, any> = {};
	/** Posés par Nitro à chaque requête : fetch et $fetch locaux qui transmettent les en-têtes de cette requête. */
	fetch?: (request: string, init?: RequestInit) => Promise<Response>;
	$fetch?: (request: string, init?: Record<string, any>) => Promise<any>;
	/** Garde le serveur en vie pour une tâche qui finit après la réponse (rafraîchissement d'un cache). */
	waitUntil?: (promise: Promise<unknown>) => void;
	_path?: string;
	_handled = false;
	private _method?: string;
	private _headers?: Headers;

	constructor(req: SimulatedRequest, res: SimulatedResponse) {
		this.node = { req, res };
	}

	get method(): string {
		if (!this._method) {
			this._method = (this.node.req.method || 'GET').toUpperCase();
		}
		return this._method;
	}

	get path(): string {
		return this._path || this.node.req.url || '/';
	}

	get headers(): Headers {
		if (!this._headers) {
			this._headers = new Headers();
			for (const [name, value] of Object.entries(this.node.req.headers)) {
				if (value) this._headers.set(name, value);
			}
		}
		return this._headers;
	}

	get handled(): boolean {
		return this._handled || this.node.res.writableEnded;
	}

	toString(): string {
		return `[${this.method}] ${this.path}`;
	}

	toJSON(): string {
		return this.toString();
	}
}

export function isEvent(input: unknown): input is H3Event {
	return typeof input === 'object' && input !== null && '__is_event__' in input;
}
