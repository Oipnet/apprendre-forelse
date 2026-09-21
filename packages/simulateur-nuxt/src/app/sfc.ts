/**
 * Compilation d'un composant monofichier (.vue) comme @vitejs/plugin-vue, en deux variantes : rendu
 * serveur (ssrRender) et navigateur (render). Le résultat est un module ES encore en TypeScript.
 * Pas encore gérés : blocs <style> (et donc les attributs data-v des styles scopés), <script> et
 * <script setup> dans le même fichier avec un template non inliné.
 */
import { compileScript, compileTemplate, parse } from 'vue/compiler-sfc';

export class SfcError extends Error {}

export function compileSfc(path: string, source: string, ssr: boolean): string {
	const { descriptor, errors } = parse(source, { filename: path });
	if (errors.length > 0) {
		throw new SfcError(`${path} : ${errors.map((error) => error.message).join(' ; ')}`);
	}
	const id = shortHash(path);
	const parts: string[] = [];
	let bindings;
	let inlined = false;
	if (descriptor.script || descriptor.scriptSetup) {
		const script = compileScript(descriptor, {
			id,
			// Comme @vitejs/plugin-vue en développement : le template n'est pas inliné dans le setup, qui
			// rend ses liaisons (c'est ce que lisent les outils de développement et `wrapper.vm` des tests).
			inlineTemplate: false,
			templateOptions: { ssr, ssrCssVars: descriptor.cssVars },
			genDefaultAs: '_sfc_main',
		});
		parts.push(script.content);
		bindings = script.bindings;
		inlined = false;
	} else {
		parts.push('const _sfc_main = {}');
	}
	if (descriptor.template && !inlined) {
		const template = compileTemplate({
			source: descriptor.template.content,
			filename: path,
			id,
			ssr,
			ssrCssVars: descriptor.cssVars,
			compilerOptions: { bindingMetadata: bindings },
		});
		if (template.errors.length > 0) {
			throw new SfcError(`${path} : ${template.errors.map((error) => (typeof error === 'string' ? error : error.message)).join(' ; ')}`);
		}
		const renderName = ssr ? 'ssrRender' : 'render';
		parts.push(template.code.replace(`export function ${renderName}`, `function _sfc_${renderName}`));
		parts.push(`_sfc_main.${renderName} = _sfc_${renderName}`);
	}
	parts.push(`_sfc_main.__file = ${JSON.stringify(path)}`, 'export default _sfc_main');
	return parts.join('\n');
}

/**
 * Contenu de `definePageMeta({...})`, que Nuxt extrait à la construction pour connaître la route
 * (layout, clé, middleware) avant d'avoir chargé la page. Seul un objet littéral est accepté.
 */
export function extractPageMeta(source: string): string | undefined {
	const start = source.indexOf('definePageMeta(');
	if (start === -1) {
		return undefined;
	}
	let depth = 0;
	let quote: string | null = null;
	const open = start + 'definePageMeta('.length;
	for (let i = open; i < source.length; i++) {
		const char = source[i];
		if (quote) {
			if (char === '\\') i++;
			else if (char === quote) quote = null;
			continue;
		}
		if (char === '"' || char === "'" || char === '`') quote = char;
		else if (char === '(' || char === '{' || char === '[') depth++;
		else if (char === ')' && depth === 0) return source.slice(open, i).trim() || undefined;
		else if (char === ')' || char === '}' || char === ']') depth--;
	}
	return undefined;
}

/** Identifiant de portée du composant. TODO styles scopés : reprendre le hachage de plugin-vue (sha256 du chemin). */
function shortHash(text: string): string {
	let hash = 5381;
	for (let i = 0; i < text.length; i++) {
		hash = ((hash << 5) + hash + text.charCodeAt(i)) >>> 0;
	}
	return hash.toString(16).padStart(8, '0');
}
