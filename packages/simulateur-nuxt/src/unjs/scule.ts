/** `snakeCase` de `scule` (unjs) : c'est lui qui transforme `public.nomDuPort` en `NUXT_PUBLIC_NOM_DU_PORT`. */

const NUMBER_CHAR_RE = /\d/;
const STR_SPLITTERS = ['-', '_', '/', '.'];

function isUppercase(char = ''): boolean | undefined {
	if (NUMBER_CHAR_RE.test(char)) {
		return undefined;
	}
	return char !== char.toLowerCase();
}

export function splitByCase(str: string): string[] {
	const parts: string[] = [];
	if (!str) {
		return parts;
	}
	let buff = '';
	let previousUpper: boolean | undefined;
	let previousSplitter: boolean | undefined;
	for (const char of str) {
		const isSplitter = STR_SPLITTERS.includes(char);
		if (isSplitter) {
			parts.push(buff);
			buff = '';
			previousUpper = undefined;
			continue;
		}
		const isUpper = isUppercase(char);
		if (previousSplitter === false) {
			if (previousUpper === false && isUpper === true) {
				parts.push(buff);
				buff = char;
				previousUpper = isUpper;
				continue;
			}
			if (previousUpper === true && isUpper === false && buff.length > 1) {
				const lastChar = buff.at(-1)!;
				parts.push(buff.slice(0, Math.max(0, buff.length - 1)));
				buff = lastChar + char;
				previousUpper = isUpper;
				continue;
			}
		}
		buff += char;
		previousUpper = isUpper;
		previousSplitter = isSplitter;
	}
	parts.push(buff);
	return parts;
}

export function snakeCase(str: string): string {
	return str ? splitByCase(str).map((part) => part.toLowerCase()).join('_') : '';
}

export function upperFirst(str: string): string {
	return str ? str[0].toUpperCase() + str.slice(1) : '';
}

export function lowerFirst(str: string): string {
	return str ? str[0].toLowerCase() + str.slice(1) : '';
}

export function pascalCase(str: string | string[]): string {
	return str ? (Array.isArray(str) ? str : splitByCase(str)).map((part) => upperFirst(part)).join('') : '';
}

export function camelCase(str: string | string[]): string {
	return lowerFirst(pascalCase(str));
}

export function kebabCase(str: string | string[], joiner = '-'): string {
	return str ? (Array.isArray(str) ? str : splitByCase(str)).map((part) => part.toLowerCase()).join(joiner) : '';
}
