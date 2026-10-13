// @vitest-environment happy-dom
import { readdirSync, readFileSync } from 'node:fs';
import { join, relative } from 'node:path';
import { describe, expect, it } from 'vitest';
import { fr } from '../src/i18n/fr.ts';
import { catalogue, m } from '../src/i18n/index.ts';

describe('catalogue', () => {
	it('m vaut fr par défaut', () => {
		expect(m).toBe(fr);
	});

	it('se replie sur fr pour une langue absente ou inconnue', () => {
		for (const lang of ['fr', 'fr-FR', 'FR', '', null, undefined, 'en', 'en-GB', 'toString', '__proto__']) expect(catalogue(lang)).toBe(fr);
	});
});

/*
 * Garde-fou du palier 1 de l'internationalisation (le même que celui du playground, adapté) : plus aucun texte
 * destiné à l'apprenant ne doit être écrit en dur dans src/ ; il vit dans src/i18n/fr.ts et passe par `m`. Le test
 * parcourt les .ts, retire les commentaires et signale toute chaîne littérale ('…', "…", `…`) qui contient une
 * lettre accentuée française, une phrase, ou un mot français courant.
 */

const SRC = join(import.meta.dirname, '..', 'src');

/** Fichiers volontairement hors périmètre (chemins relatifs à src/). */
const FICHIERS_EXCLUS = new Set<string>([
	// Les raisons HTTP standard (« Not Found »…), celles que Node et h3 renvoient : pas un texte de l'interface.
	'http.ts',
]);

/**
 * Chaînes tolérées, par fichier : ce ne sont pas des textes de l'interface même si elles en ont l'allure. La plupart
 * imitent volontairement un outil réel (Nuxt, Nitro, h3, Vitest, @nuxt/test-utils, destr) : l'apprenant doit lire
 * exactement le message qu'il lirait avec le vrai outil, et le chercher tel quel dans sa documentation.
 * Chaque entrée dit pourquoi.
 */
const EXCEPTIONS: Record<string, string[]> = {
	// Les messages et pages d'erreur de Nuxt (statusMessage, [NUXT_E…], avertissements de Vue).
	'app/renderer.ts': [
		'Page Not Found: /__nuxt_error',
		// Erreur de configuration de l'hôte (option clientBundle oubliée), jamais vue par l'apprenant.
		'Module navigateur du simulateur absent (option clientBundle).',
	],
	'app/runtime/app.ts': ['Page not found', 'Internal server error', '[Vue warn]: ', '[NUXT_E2004] Unknown route middleware: \'', '\'. Valid middleware: ', 'Page Not Found: ', 'Page not found: '],
	'app/runtime/components.ts': ['Invalid layout \\`', '\\` selected. Available layouts: ', 'Create a \\`layouts/'],
	'app/runtime/cookie.ts': ['The cookie "'],
	'app/runtime/data.ts': [
		'Incompatible options detected for "',
		'You can use a different key or move the call to a composable to ensure the options are shared across calls.',
		'Wrap the call: `watch(source, () => execute())` instead of `watch(source, execute)`.',
		'Return a value from the handler function (e.g. `return null` instead of returning nothing).',
	],
	'app/runtime/head.ts': ['[NUXT_E6001] Nuxt head instance not found in the current context.'],
	'app/runtime/nuxt.ts': ['A composable that requires access to the Nuxt instance was called outside of a plugin, Nuxt hook, Nuxt middleware, or Vue setup function. This is probably not a Nuxt bug.'],
	'app/runtime/router.ts': ['[NUXT_E2002] Cannot navigate to a URL with \'', '[NUXT_E2001] Navigating to an external URL is not allowed by default. Use `navigateTo(url, { external: true })`.'],
	// L'erreur de Vitest pour un import introuvable.
	'compiler.ts': [
		'Cannot find module \'',
		'\'.\\n\\n- If you rely on tsconfig.json\'s "paths" to resolve modules, please install "vite-tsconfig-paths" plugin to handle module resolution.\\n- Make sure you don\'t have relative aliases in your Vitest config. Use absolute paths instead. Read more: https://vitest.dev/guide/common-errors',
	],
	// Les erreurs de h3 et de Nitro (validation, corps JSON, cache, page d'erreur).
	'h3/utils.ts': ['Validation Error', 'Bad Request', 'Invalid JSON body', '[h3] Cannot send '],
	'nitro/cache.ts': ['Malformed data read from cache.'],
	'nitro/errors.ts': ['Server Error'],
	'simulator.ts': ['Cannot find any path matching ', 'Invalid lazy handler result. It should be a function: ', 'Page not found: '],
	// Les erreurs de @nuxt/test-utils et de Vitest, et l'en-tête du rapport de Vitest.
	'testing/nuxt/environment.ts': ['() is a macro and it did not get transpiled. This may be an internal bug of @nuxt/test-utils.'],
	'testing/nuxt/macros.ts': ['The first argument of mockComponent() must be a string literal', 'The first argument of ', 'Cannot find import "'],
	'testing/nuxt/window.ts': ['Cannot find any path matching '],
	'testing/run.ts': [' Test Files  '],
	'testing/vitest.ts': ['Timed out in waitFor!', 'Timed out in waitUntil!'],
	// Les erreurs de destr (unjs).
	'unjs/destr.ts': ['[destr] Invalid JSON', '[destr] Possible prototype pollution'],
};

/** Les messages de débogage pour les développeurs (console.warn('…', e)) ne sont pas traduits. */
const CONSOLE = /\bconsole\.\w+\(\s*$/;

/** Retourne le contenu de chaque chaîne littérale (hors commentaires), avec sa ligne. */
function chainesLitterales(source: string): { texte: string; ligne: number; avant: string }[] {
	const chaines: { texte: string; ligne: number; avant: string }[] = [];
	let i = 0;
	let ligne = 1;
	/** Pile des templates ouverts : profondeur d'accolades dans chaque `${…}`. */
	const templates: number[] = [];
	let dernierSignificatif = '';

	const lireTemplate = () => {
		// i pointe juste après ` ou }, on lit jusqu'à ` ou ${
		let texte = '';
		const debut = ligne;
		const avant = source.slice(Math.max(0, i - 41), i - 1);
		while (i < source.length) {
			const c = source[i];
			if (c === '\\') { texte += source.slice(i, i + 2); if (source[i + 1] === '\n') ligne++; i += 2; continue; }
			if (c === '`') { i++; chaines.push({ texte, ligne: debut, avant }); dernierSignificatif = '`'; return; }
			if (c === '$' && source[i + 1] === '{') { i += 2; chaines.push({ texte, ligne: debut, avant }); templates.push(0); dernierSignificatif = '{'; return; }
			if (c === '\n') ligne++;
			texte += c;
			i++;
		}
	};

	while (i < source.length) {
		const c = source[i];
		const suivant = source[i + 1];
		if (c === '\n') { ligne++; i++; continue; }
		if (/\s/.test(c)) { i++; continue; }
		if (c === '/' && suivant === '/') { while (i < source.length && source[i] !== '\n') i++; continue; }
		if (c === '/' && suivant === '*') {
			const fin = source.indexOf('*/', i + 2);
			const bloc = source.slice(i, fin < 0 ? source.length : fin + 2);
			ligne += bloc.split('\n').length - 1;
			i += bloc.length;
			continue;
		}
		if (c === '"' || c === "'") {
			let texte = '';
			const debut = ligne;
			const avant = source.slice(Math.max(0, i - 40), i);
			i++;
			while (i < source.length && source[i] !== c && source[i] !== '\n') {
				if (source[i] === '\\') { texte += source.slice(i, i + 2); i += 2; continue; }
				texte += source[i++];
			}
			i++;
			chaines.push({ texte, ligne: debut, avant });
			dernierSignificatif = 'a';
			continue;
		}
		if (c === '`') { i++; lireTemplate(); continue; }
		if (c === '{' && templates.length) { templates[templates.length - 1]++; }
		if (c === '}' && templates.length) {
			if (templates[templates.length - 1] === 0) { templates.pop(); i++; lireTemplate(); continue; }
			templates[templates.length - 1]--;
		}
		if (c === '/') {
			// Littéral d'expression régulière si le jeton précédent ne peut pas terminer une expression.
			const estRegex = dernierSignificatif === '' || /[(,=:[!&|?{};+\-*%<>~^]/.test(dernierSignificatif)
				|| /\b(return|typeof|case|in|of|delete|void|throw|new)$/.test(source.slice(Math.max(0, i - 10), i).trimEnd());
			if (estRegex) {
				i++;
				let classe = false;
				while (i < source.length && source[i] !== '\n') {
					const r = source[i];
					if (r === '\\') { i += 2; continue; }
					if (r === '[') classe = true;
					else if (r === ']') classe = false;
					else if (r === '/' && !classe) break;
					i++;
				}
				i++;
				while (/[a-z]/i.test(source[i] ?? '')) i++;
				dernierSignificatif = 'a';
				continue;
			}
		}
		if (/[\w$]/.test(c)) {
			while (i < source.length && /[\w$]/.test(source[i])) i++;
			dernierSignificatif = 'a';
			const mot = source.slice(0, i).match(/[\w$]+$/)?.[0] ?? '';
			if (/^(return|typeof|case|in|of|delete|void|throw|new|yield|await)$/.test(mot)) dernierSignificatif = '(';
			continue;
		}
		dernierSignificatif = c === ')' || c === ']' ? 'a' : c;
		i++;
	}
	return chaines;
}

const ACCENT = /[àâäçéèêëîïôöùûüÿœæÀÂÄÇÉÈÊËÎÏÔÖÙÛÜŸŒÆ]/;
const PHRASE = /(?<![\p{L}\w+-])\p{Lu}\p{Ll}+ \p{L}+/u;
/** Une phrase française sans accent ni majuscule (« vi.mock n'est pas disponible… ») : élision, ou mot outil courant. */
const FRANCAIS = /(?<![\p{L}\w])(?:[cdjlmnst]|qu)'\p{L}|(?<![\p{L}\w.-])(?:pas|aucun|aucune|votre|vos|une|des|est|dans|ne|ou)(?![\p{L}\w.-])/u;

function fichiersTs(dossier: string): string[] {
	return readdirSync(dossier, { withFileTypes: true }).flatMap((entree) => {
		const chemin = join(dossier, entree.name);
		if (entree.isDirectory()) return entree.name === 'i18n' && dossier === SRC ? [] : fichiersTs(chemin);
		return entree.name.endsWith('.ts') && !entree.name.endsWith('.d.ts') ? [chemin] : [];
	});
}

describe('aucun texte en dur', () => {
	it('repère chaînes, templates et ignore commentaires et regex', () => {
		const trouve = chainesLitterales("// Une phrase\nconst a = /é'/g; const b = `x ${'Un texte'} y`; /* Autre phrase */");
		expect(trouve.map((c) => c.texte)).toEqual(['x ', 'Un texte', ' y']);
	});

	it('repère une phrase française sans accent', () => {
		expect(["vi.mock n'est pas disponible", 'aucun test', "don't", 'README.md', 'pages/index.vue'].map((texte) => FRANCAIS.test(texte))).toEqual([true, true, false, false, false]);
	});

	const fichiers = fichiersTs(SRC).map((f) => relative(SRC, f).split('\\').join('/')).filter((f) => !FICHIERS_EXCLUS.has(f));

	it.each(fichiers)('%s', (fichier) => {
		const tolerees = EXCEPTIONS[fichier] ?? [];
		const fautives = chainesLitterales(readFileSync(join(SRC, fichier), 'utf8'))
			.filter(({ avant }) => !CONSOLE.test(avant))
			.filter(({ texte }) => (ACCENT.test(texte) || PHRASE.test(texte) || FRANCAIS.test(texte)) && !tolerees.includes(texte))
			.map(({ texte, ligne }) => `${fichier}:${ligne} ${JSON.stringify(texte)}`);
		expect(fautives).toEqual([]);
	});
});
