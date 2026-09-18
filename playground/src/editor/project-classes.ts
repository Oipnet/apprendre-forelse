/**
 * Les classes du projet — `App\Entity\Plat`, le DTO que l'apprenant vient d'écrire — ne sont dans aucun index :
 * elles n'existent que le temps de l'exercice. On les relit de ses fichiers, à la même forme que l'index généré
 * par Reflection (tools/build-completion.php), pour que la complétion les traite comme les autres.
 *
 * L'analyse est volontairement naïve — des expressions régulières, pas un analyseur PHP — et suppose ce que
 * PSR-4 impose de toute façon : une déclaration de premier niveau par fichier.
 */

export interface MethodInfo {
	name: string;
	params: string[];
	returns: string;
	static: boolean;
	protected: boolean;
	declaringClass: string;
	doc: string;
}

export interface ClassInfo {
	short: string;
	kind: 'class' | 'interface' | 'trait' | 'enum' | 'attribute';
	abstract: boolean;
	doc: string;
	constructor: string[];
	constants: string[];
	methods: MethodInfo[];
	/** FQCN de la classe parente, le temps d'hériter ses méthodes. */
	extends?: string;
}

/** Première phrase d'un bloc de documentation, sans les décorations ni les annotations. */
function summary(block: string | undefined): string {
	if (!block) return '';
	const lines: string[] = [];
	for (const raw of block.split('\n')) {
		const line = raw.replace(/^\s*(\/\*\*|\*\/|\*)?\s?/, '').trim();
		if (line === '') {
			if (lines.length) break;
			continue;
		}
		if (line.startsWith('@')) break;
		lines.push(line);
	}
	return lines.join(' ');
}

/**
 * Le texte privé de ses attributs `#[…]`. Un attribut peut contenir des crochets (`#[ORM\Column(options:
 * ['default' => 1])]`) : on saute jusqu'au crochet qui lui correspond, en ignorant ceux des chaînes.
 */
function withoutAttributes(text: string): string {
	let out = '';
	for (let i = 0; i < text.length; i++) {
		if (text[i] !== '#' || text[i + 1] !== '[') {
			out += text[i];
			continue;
		}
		let depth = 0;
		let quote = '';
		for (i++; i < text.length; i++) {
			const ch = text[i];
			if (quote) {
				if (ch === '\\') i++;
				else if (ch === quote) quote = '';
			} else if (ch === "'" || ch === '"') quote = ch;
			else if (ch === '[') depth++;
			else if (ch === ']' && --depth === 0) break;
		}
	}
	return out;
}

/** Le bloc `/** … *\/` qui précède immédiatement la position donnée, les attributs mis à part. */
function docBefore(code: string, position: number): string {
	const before = code.slice(0, position);
	const end = before.lastIndexOf('*/');
	if (end < 0 || withoutAttributes(before.slice(end + 2)).trim() !== '') return '';
	const start = before.lastIndexOf('/**', end);
	return start < 0 ? '' : summary(before.slice(start, end));
}

/**
 * Les paramètres d'un appel ouvert en `open`, découpés sur les virgules de premier niveau : une valeur par
 * défaut peut contenir des parenthèses, des crochets ou une virgule (`array $o = ['a' => 1]`).
 */
function parameters(code: string, open: number): { params: string[]; end: number } {
	let depth = 0;
	let quote = '';
	let start = open + 1;
	const params: string[] = [];
	for (let i = open; i < code.length; i++) {
		const ch = code[i];
		if (quote) {
			if (ch === '\\') i++;
			else if (ch === quote) quote = '';
			continue;
		}
		if (ch === "'" || ch === '"') quote = ch;
		else if (ch === '(' || ch === '[' || ch === '{') depth++;
		else if (ch === ')' || ch === ']' || ch === '}') {
			depth--;
			if (depth === 0) {
				const last = code.slice(start, i).trim();
				if (last !== '') params.push(last);
				return { params, end: i };
			}
		} else if (ch === ',' && depth === 1) {
			params.push(code.slice(start, i).trim());
			start = i + 1;
		}
	}
	return { params, end: code.length };
}

const DECLARATION = /^(?<modifiers>(?:(?:abstract|final|readonly)\s+)*)(?<kind>class|interface|trait|enum)\s+(?<name>\w+)(?:\s*:\s*\w+)?(?<heritage>[^{]*)/m;
const METHOD = /^[ \t]*(?<modifiers>(?:(?:public|protected|private|static|final|abstract)\s+)*)function\s+(?<name>\w+)\s*\(/gm;
const CONSTANT = /^[ \t]*(?:(?:public|final)\s+)*(?:const\s+(?:[\w|?\\]+\s+)?(?<name>[A-Z_]\w*)\s*=|case\s+(?<enumCase>\w+)\s*[=;])/gm;

/** Résout un nom écrit dans un fichier (court, aliasé ou absolu) en nom pleinement qualifié. */
function resolve(name: string, namespace: string, uses: Map<string, string>): string {
	const clean = name.replace(/^[?\\]+/, '');
	const [head, ...rest] = clean.split('\\');
	const imported = uses.get(head);
	if (name.startsWith('\\')) return clean;
	if (imported) return [imported, ...rest].join('\\');
	return namespace ? `${namespace}\\${clean}` : clean;
}

/** Les classes déclarées par un fichier PHP du projet, par nom pleinement qualifié. */
function classesOf(code: string): Record<string, ClassInfo> {
	const namespace = code.match(/^namespace\s+([\w\\]+)\s*;/m)?.[1] ?? '';
	const uses = new Map<string, string>();
	for (const [, fqcn, alias] of code.matchAll(/^use\s+(?!function\s|const\s)([\w\\]+)(?:\s+as\s+(\w+))?\s*;/gm)) {
		uses.set(alias ?? fqcn.split('\\').pop()!, fqcn);
	}

	const declaration = DECLARATION.exec(code);
	if (!declaration?.groups) return {};
	const { modifiers, kind, name, heritage } = declaration.groups;
	const body = code.slice(declaration.index + declaration[0].length);
	const parent = heritage.match(/\bextends\s+([\w\\]+)/)?.[1];

	const info: ClassInfo = {
		short: name,
		kind: kind as ClassInfo['kind'],
		abstract: modifiers.includes('abstract'),
		doc: docBefore(code, declaration.index),
		constructor: [],
		constants: [],
		methods: [],
		extends: parent ? resolve(parent, namespace, uses) : undefined,
	};

	METHOD.lastIndex = 0;
	for (let m = METHOD.exec(body); m; m = METHOD.exec(body)) {
		const flags = m.groups!.modifiers;
		const open = m.index + m[0].length - 1;
		const { params, end } = parameters(body, open);
		if (m.groups!.name === '__construct') {
			info.constructor = params;
			// Les propriétés promues ne sont pas des méthodes, mais bien des paramètres : rien de plus à faire.
			METHOD.lastIndex = end;
			continue;
		}
		if (flags.includes('private')) {
			METHOD.lastIndex = end;
			continue;
		}
		info.methods.push({
			name: m.groups!.name,
			params,
			returns: body.slice(end + 1).match(/^\s*:\s*([\w|?\\]+)/)?.[1] ?? '',
			static: flags.includes('static'),
			protected: flags.includes('protected'),
			declaringClass: name,
			doc: docBefore(body, m.index),
		});
		METHOD.lastIndex = end;
	}

	CONSTANT.lastIndex = 0;
	for (let c = CONSTANT.exec(body); c; c = CONSTANT.exec(body)) {
		const constant = c.groups!.name ?? c.groups!.enumCase;
		if (constant) info.constants.push(constant);
	}

	return { [namespace ? `${namespace}\\${name}` : name]: info };
}

/**
 * Les classes déclarées par les fichiers PHP du projet. `inherited` fournit les classes déjà connues (l'index
 * de l'environnement) : une classe du projet qui en étend une hérite de ses méthodes, comme dans l'index où
 * l'héritage est déjà aplati. Sans quoi `$this->` d'un contrôleur ne proposerait rien.
 */
export function projectClasses(files: Record<string, string>, inherited: Record<string, ClassInfo> = {}): Record<string, ClassInfo> {
	const own: Record<string, ClassInfo> = {};
	for (const [path, code] of Object.entries(files)) {
		if (path.endsWith('.php')) Object.assign(own, classesOf(code));
	}

	const flatten = (fqcn: string, depth = 0): MethodInfo[] => {
		const info = own[fqcn] ?? inherited[fqcn];
		if (!info || depth > 4) return [];
		const parent = info.extends ? flatten(info.extends, depth + 1) : [];
		const names = new Set(info.methods.map((m) => m.name));
		return [...info.methods, ...parent.filter((m) => !names.has(m.name))];
	};
	for (const [fqcn, info] of Object.entries(own)) {
		if (info.extends) info.methods = flatten(fqcn);
	}

	return own;
}
