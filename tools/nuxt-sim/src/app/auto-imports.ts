/**
 * Auto-imports de l'application, comme unimport dans Nuxt : un identifiant connu, utilisé et ni importé
 * ni déclaré dans le fichier, reçoit sa ligne d'import. Même mécanisme côté serveur et navigateur.
 */

/** Préréglage « vue » de Nuxt 4.5 (nuxt/dist/index.mjs, vuePreset). */
export const VUE_AUTO_IMPORTS = [
	'withCtx', 'withDirectives', 'withKeys', 'withMemo', 'withModifiers', 'withScopeId',
	'onActivated', 'onBeforeMount', 'onBeforeUnmount', 'onBeforeUpdate', 'onDeactivated', 'onErrorCaptured', 'onMounted',
	'onRenderTracked', 'onRenderTriggered', 'onServerPrefetch', 'onUnmounted', 'onUpdated',
	'computed', 'customRef', 'isProxy', 'isReactive', 'isReadonly', 'isRef', 'markRaw', 'proxyRefs', 'reactive', 'readonly',
	'ref', 'shallowReactive', 'shallowReadonly', 'shallowRef', 'toRaw', 'toRef', 'toRefs', 'triggerRef', 'unref',
	'watch', 'watchEffect', 'watchPostEffect', 'watchSyncEffect', 'onWatcherCleanup', 'isShallow',
	'effect', 'effectScope', 'getCurrentScope', 'onScopeDispose',
	'defineComponent', 'defineAsyncComponent', 'resolveComponent', 'getCurrentInstance', 'h', 'inject', 'hasInjectionContext',
	'nextTick', 'provide', 'toValue', 'useModel', 'useAttrs', 'useCssModule', 'useCssVars', 'useSlots', 'useTransitionState',
	'useId', 'useTemplateRef', 'useShadowRoot',
];

/** Composables de Nuxt disponibles dans le simulateur (voir runtime/nuxt.ts). */
export const NUXT_AUTO_IMPORTS = [
	'useNuxtApp', 'useRoute', 'useRouter', 'definePageMeta', 'useRuntimeConfig', 'useState', 'clearNuxtState', 'useAppConfig', 'defineAppConfig',
	'useAsyncData', 'useLazyAsyncData', 'useNuxtData', 'refreshNuxtData', 'clearNuxtData', 'useFetch', 'useLazyFetch', 'callOnce', '$fetch',
	'createError', 'isNuxtError', 'useRequestEvent', 'useRequestFetch',
];

/** Pour chaque nom auto-importé : le module et le nom exporté. */
export type ImportTable = Map<string, { from: string; name: string }>;

/** Table des auto-imports : préréglages de Vue et de Nuxt, puis exports du projet (sans écraser un préréglage). */
export function importTable(presetSource: string, project: { as: string; name: string; file: string }[], presets = [...VUE_AUTO_IMPORTS, ...NUXT_AUTO_IMPORTS]): ImportTable {
	const table: ImportTable = new Map();
	for (const name of presets) table.set(name, { from: presetSource, name });
	for (const entry of project) {
		if (!table.has(entry.as)) table.set(entry.as, { from: `~~/${entry.file}`, name: entry.name });
	}
	return table;
}

/** Ajoute les `import { … } from '…'` des auto-imports utilisés par ce module ES. */
export function injectAutoImports(code: string, table: ImportTable, self?: string): string {
	const names = [...table.keys()];
	// Dans un template, un identifiant inconnu est compilé en `_ctx.nom` : Nuxt le remplace par l'import
	// (addon vueTemplate d'unimport), ce qui rend `{{ useRoute().path }}` utilisable.
	code = code.replace(new RegExp(`\\b_ctx\\.(${names.map(escape).join('|')})(?![\\w$])`, 'g'), '$1');
	const scanned = stripCommentsAndStrings(code);
	const bySource = new Map<string, string[]>();
	for (const name of names) {
		const entry = table.get(name)!;
		if (self && entry.from === `~~/${self}`) continue;
		if (!new RegExp(`(?<![\\w$.])${escape(name)}(?![\\w$])`).test(scanned) || isDeclared(scanned, name)) continue;
		const specifiers = bySource.get(entry.from) ?? [];
		specifiers.push(entry.name === name ? name : `${entry.name} as ${name}`);
		bySource.set(entry.from, specifiers);
	}
	const lines = [...bySource].map(([from, specifiers]) => `import { ${specifiers.join(', ')} } from ${JSON.stringify(from)};`);
	return lines.length > 0 ? `${lines.join('\n')}\n${code}` : code;
}

/**
 * Clés automatiques de Nuxt (plugin keyed-functions du compilateur) : un appel à une de ces fonctions
 * avec moins d'arguments que prévu reçoit en dernier argument une clé propre à cet emplacement d'appel,
 * « $ » suivi de dix caractères (un hachage du fichier et d'un compteur commun au fichier). Deux
 * `useState()` sans clé ne partagent donc rien, et deux `useFetch('/api/x')` font deux requêtes.
 * Nuxt s'abstient quand la clé est déjà écrite : premier argument chaîne pour `useState` et
 * `useAsyncData`, deuxième pour `useFetch`.
 */
const KEYED_FUNCTIONS: Record<string, { argumentLength: number; keyArgument?: number }> = {
	callOnce: { argumentLength: 3 },
	useState: { argumentLength: 2, keyArgument: 0 },
	useFetch: { argumentLength: 3, keyArgument: 1 },
	useAsyncData: { argumentLength: 3, keyArgument: 0 },
	useLazyAsyncData: { argumentLength: 3, keyArgument: 0 },
	useLazyFetch: { argumentLength: 3, keyArgument: 1 },
};

export function addStateKeys(code: string, file: string): string {
	let count = 0;
	let result = '';
	let last = 0;
	// Recherche sur une copie sans commentaires ni chaînes (de même longueur) : les positions restent justes.
	const stripped = stripCommentsAndStrings(code);
	const re = new RegExp(`(?<![\\w$.])(${Object.keys(KEYED_FUNCTIONS).join('|')})\\s*\\(`, 'g');
	// Comme Nuxt (walkContext.skip) : on ne regarde pas les appels imbriqués dans les arguments d'un appel déjà vu.
	let skipUntil = -1;
	for (let match = re.exec(stripped); match; match = re.exec(stripped)) {
		if (match.index < skipUntil) continue;
		const meta = KEYED_FUNCTIONS[match[1]];
		const open = match.index + match[0].length - 1;
		const call = scanArguments(code, open);
		if (!call) continue;
		skipUntil = call.close;
		if (call.count >= meta.argumentLength && !call.spread) continue;
		if (meta.keyArgument !== undefined && isStringLiteral(call.args[meta.keyArgument])) continue;
		const key = `'$${shortHash(`${file}-${++count}`)}'`;
		result += code.slice(last, call.close) + (call.count > 0 ? (call.trailingComma ? ` ${key}` : `, ${key}`) : key);
		last = call.close;
	}
	return result + code.slice(last);
}

function isStringLiteral(argument: string | undefined): boolean {
	const text = argument?.trim() ?? '';
	return /^'(?:\\.|[^'\\])*'$|^"(?:\\.|[^"\\])*"$|^`(?:\\.|[^`\\])*`$/.test(text);
}

function scanArguments(code: string, open: number): { close: number; count: number; spread: boolean; trailingComma: boolean; args: string[] } | undefined {
	let argStart = open + 1;
	const args: string[] = [];
	let depth = 0;
	let count = 0;
	let hasContent = false;
	let lastSignificant = '';
	let spread = false;
	for (let i = open; i < code.length; i++) {
		const char = code[i];
		if (char === '"' || char === "'" || char === '`') {
			i = skipString(code, i);
			hasContent = true;
			lastSignificant = 'x';
			continue;
		}
		if (char === '(' || char === '[' || char === '{') {
			depth++;
			if (depth > 1) { hasContent = true; lastSignificant = char; }
			continue;
		}
		if (char === ')' || char === ']' || char === '}') {
			depth--;
			if (depth === 0) {
				args.push(code.slice(argStart, i));
				return { close: i, count: hasContent ? count + 1 : 0, spread, trailingComma: lastSignificant === ',', args };
			}
			lastSignificant = char;
			continue;
		}
		if (depth === 1 && char === ',') {
			args.push(code.slice(argStart, i));
			argStart = i + 1;
			count++;
			lastSignificant = ',';
			continue;
		}
		if (depth === 1 && code.startsWith('...', i)) spread = true;
		if (!/\s/.test(char)) { hasContent = true; lastSignificant = char; }
	}
	return undefined;
}

function skipString(code: string, start: number): number {
	const quote = code[start];
	for (let i = start + 1; i < code.length; i++) {
		if (code[i] === '\\') i++;
		else if (code[i] === quote) return i;
	}
	return code.length;
}

/** Dix caractères de base64url tirés d'un hachage FNV-1a (la forme de la clé de Nuxt, pas sa valeur). */
function shortHash(input: string): string {
	let h1 = 0x811c9dc5;
	let h2 = 0x01000193;
	for (let i = 0; i < input.length; i++) {
		h1 = Math.imul(h1 ^ input.charCodeAt(i), 16777619) >>> 0;
		h2 = Math.imul(h2 ^ input.charCodeAt(i), 2246822519) >>> 0;
	}
	const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
	let out = '';
	let value = BigInt(h1) * 4294967296n + BigInt(h2);
	for (let i = 0; i < 10; i++) {
		out += alphabet[Number(value % 64n)];
		value /= 64n;
	}
	return out;
}

function escape(name: string): string {
	return name.replace(/\$/g, '\\$');
}

function isDeclared(code: string, name: string): boolean {
	const importRe = new RegExp(`import\\s*(?:[\\w$]+\\s*,\\s*)?\\{[^}]*(?<![\\w$])(?:${escape(name)}|as\\s+${escape(name)})(?![\\w$])[^}]*\\}\\s*from`);
	const defaultImportRe = new RegExp(`import\\s+${escape(name)}\\s+from`);
	const declarationRe = new RegExp(`(?:const|let|var|function|class)\\s+${escape(name)}(?![\\w$])`);
	return importRe.test(code) || defaultImportRe.test(code) || declarationRe.test(code);
}

function stripCommentsAndStrings(code: string): string {
	return code
		.replace(/\/\*[\s\S]*?\*\//g, (m) => ' '.repeat(m.length))
		.replace(/(^|[^:\\])\/\/.*$/gm, (m, p) => p + ' '.repeat(m.length - p.length))
		.replace(/'(?:\\.|[^'\\\n])*'|"(?:\\.|[^"\\\n])*"/g, (m) => `"${' '.repeat(m.length - 2)}"`);
}
