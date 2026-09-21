/** Erreur HTTP de h3 1.x (`createError`), avec la même logique de valeurs par défaut. */
export class H3Error<DataT = unknown> extends Error {
	static __h3_error__ = true;
	statusCode = 500;
	fatal = false;
	unhandled = false;
	statusMessage?: string;
	data?: DataT;
	override cause?: unknown;

	constructor(message: string, options: { cause?: unknown } = {}) {
		super(message, options);
		if (options.cause && !this.cause) {
			this.cause = options.cause;
		}
	}

	toJSON() {
		const json: Record<string, unknown> = { message: this.message, statusCode: sanitizeStatusCode(this.statusCode, 500) };
		if (this.statusMessage) json.statusMessage = sanitizeStatusMessage(this.statusMessage);
		if (this.data !== undefined) json.data = this.data;
		return json;
	}
}

export interface ErrorInput<DataT = unknown> {
	message?: string;
	statusCode?: number;
	status?: number;
	statusMessage?: string;
	statusText?: string;
	data?: DataT;
	cause?: unknown;
	stack?: string;
	fatal?: boolean;
	unhandled?: boolean;
}

export function isError(input: unknown): input is H3Error {
	return (input as { constructor?: { __h3_error__?: boolean } } | null)?.constructor?.__h3_error__ === true;
}

export function createError<DataT = unknown>(input: string | ErrorInput<DataT> | Error): H3Error<DataT> {
	if (typeof input === 'string') {
		return new H3Error<DataT>(input);
	}
	if (isError(input)) {
		return input as H3Error<DataT>;
	}
	const source = input as ErrorInput<DataT>;
	const error = new H3Error<DataT>(source.message ?? source.statusMessage ?? '', { cause: source.cause || source });
	if ('stack' in source) {
		try {
			Object.defineProperty(error, 'stack', { get: () => source.stack });
		} catch {
			// Pile en lecture seule : on garde celle de l'erreur créée.
		}
	}
	if (source.data) error.data = source.data;
	if (source.statusCode) error.statusCode = sanitizeStatusCode(source.statusCode, error.statusCode);
	else if (source.status) error.statusCode = sanitizeStatusCode(source.status, error.statusCode);
	if (source.statusMessage) error.statusMessage = source.statusMessage;
	else if (source.statusText) error.statusMessage = source.statusText;
	if (error.statusMessage && sanitizeStatusMessage(error.statusMessage) !== error.statusMessage) {
		console.warn('[h3] Please prefer using `message` for longer error messages instead of `statusMessage`. In the future, `statusMessage` will be sanitized by default.');
	}
	if (source.fatal !== undefined) error.fatal = source.fatal;
	if (source.unhandled !== undefined) error.unhandled = source.unhandled;
	return error;
}

/** Une raison de statut ne garde que les caractères ASCII imprimables et la tabulation. */
export function sanitizeStatusMessage(message = ''): string {
	return message.replace(/[^	 -~]/g, '');
}

export function sanitizeStatusCode(code: string | number | undefined, fallback = 200): number {
	if (!code) return fallback;
	const value = typeof code === 'string' ? Number.parseInt(code, 10) : code;
	return value < 100 || value > 999 ? fallback : value;
}
