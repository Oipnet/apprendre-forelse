/** Fonctions d'URL de `ufo` (unjs) dont h3 et Nitro se servent, reproduites à l'identique. */

const PLUS_RE = /\+/g;
const ENC_SLASH_RE = /%2f/gi;

export function decode(text = ''): string {
	try {
		return decodeURIComponent(`${text}`);
	} catch {
		return `${text}`;
	}
}

/** Décode un chemin sans transformer %2F en « / » (il resterait sinon un séparateur de plus). */
export function decodePath(text: string): string {
	return decode(text.replace(ENC_SLASH_RE, '%252F'));
}

export type QueryValue = string | string[];

export function parseQuery(parametersString = ''): Record<string, QueryValue> {
	const object: Record<string, QueryValue> = Object.create(null);
	if (parametersString[0] === '?') {
		parametersString = parametersString.slice(1);
	}
	for (const parameter of parametersString.split('&')) {
		const s = parameter.match(/([^=]+)=?(.*)/) || [];
		if (s.length < 2) {
			continue;
		}
		const key = decode(s[1].replace(PLUS_RE, ' '));
		if (key === '__proto__' || key === 'constructor') {
			continue;
		}
		const value = decode((s[2] || '').replace(PLUS_RE, ' '));
		const current = object[key];
		if (current === undefined) {
			object[key] = value;
		} else if (Array.isArray(current)) {
			current.push(value);
		} else {
			object[key] = [current, value];
		}
	}
	return object;
}

/** Query string d'un chemin (« ?a=1 »), ou chaîne vide ; le fragment est ignoré. */
export function searchOf(path: string): string {
	const withoutHash = path.split('#')[0];
	const index = withoutHash.indexOf('?');
	return index === -1 ? '' : withoutHash.slice(index);
}

export function withoutTrailingSlash(input = ''): string {
	const [path, ...rest] = input.split('?');
	const trimmed = path.endsWith('/') ? path.slice(0, -1) : path;
	return (trimmed || '/') + (rest.length > 0 ? `?${rest.join('?')}` : '');
}
