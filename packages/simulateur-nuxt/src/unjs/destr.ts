/**
 * `destr` (unjs), que h3 utilise pour lire un corps JSON et Nitro pour lire les variables d'environnement :
 * plus tolérant que JSON.parse (« true », « null »… reconnus), et qui rend la chaîne telle quelle
 * quand ce n'est pas du JSON, sauf en mode strict.
 */
const SUSPECT_PROTO = /"(?:_|\\u0{2}5[Ff]){2}(?:p|\\u0{2}70)(?:r|\\u0{2}72)(?:o|\\u0{2}6[Ff])(?:t|\\u0{2}74)(?:o|\\u0{2}6[Ff])(?:_|\\u0{2}5[Ff]){2}"\s*:/;
const SUSPECT_CONSTRUCTOR = /"(?:c|\\u0063)(?:o|\\u006[Ff])(?:n|\\u006[Ee])(?:s|\\u0073)(?:t|\\u0074)(?:r|\\u0072)(?:u|\\u0075)(?:c|\\u0063)(?:t|\\u0074)(?:o|\\u006[Ff])(?:r|\\u0072)"\s*:/;
const JSON_SIGNATURE = /^\s*["[{]|^\s*-?\d{1,16}(\.\d{1,17})?([Ee][+-]?\d+)?\s*$/;

function dropDangerousKeys(key: string, value: unknown): unknown {
	if (key === '__proto__' || (key === 'constructor' && value && typeof value === 'object' && 'prototype' in value)) {
		console.warn(`[destr] Dropping "${key}" key to prevent prototype pollution.`);
		return undefined;
	}
	return value;
}

export function destr(value: unknown, options: { strict?: boolean } = {}): unknown {
	if (typeof value !== 'string') {
		return value;
	}
	if (value[0] === '"' && value[value.length - 1] === '"' && !value.includes('\\')) {
		return value.slice(1, -1);
	}
	const trimmed = value.trim();
	if (trimmed.length <= 9) {
		switch (trimmed.toLowerCase()) {
			case 'true': return true;
			case 'false': return false;
			case 'undefined': return undefined;
			case 'null': return null;
			case 'nan': return Number.NaN;
			case 'infinity': return Number.POSITIVE_INFINITY;
			case '-infinity': return Number.NEGATIVE_INFINITY;
		}
	}
	if (!JSON_SIGNATURE.test(value)) {
		if (options.strict) {
			throw new SyntaxError('[destr] Invalid JSON');
		}
		return value;
	}
	try {
		if (SUSPECT_PROTO.test(value) || SUSPECT_CONSTRUCTOR.test(value)) {
			if (options.strict) {
				throw new Error('[destr] Possible prototype pollution');
			}
			return JSON.parse(value, dropDangerousKeys);
		}
		return JSON.parse(value);
	} catch (error) {
		if (options.strict) {
			throw error;
		}
		return value;
	}
}
