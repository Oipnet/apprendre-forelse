/**
 * Ce que Nuxt découvre tout seul dans le projet : les composants de `app/components` (nommés comme
 * scanComponents de Nuxt) et les exports de `app/composables`, `app/utils`, `shared/utils` et
 * `shared/types` (comme scanDirExports d'unimport), qui deviennent des auto-imports.
 */
import { camelCase, pascalCase, splitByCase } from '../unjs/scule.ts';

const COMPONENTS_DIR = 'app/components/';
const COMPONENT_EXT_RE = /\.(vue|tsx?|jsx?|mts|mjs)$/;
const COMPONENT_MODE_RE = /(?<=\.)(client|server)(\.global|\.island)*$/;
const MODE_REPLACEMENT_RE = /(\.(client|server))?(\.global|\.island)*$/;

export interface ScannedComponent {
	pascalName: string;
	file: string;
	mode: 'all' | 'client' | 'server';
}

export function scanComponents(files: Iterable<string>): ScannedComponent[] {
	const components: ScannedComponent[] = [];
	const names = new Set<string>();
	for (const file of [...files].filter((path) => path.startsWith(COMPONENTS_DIR) && COMPONENT_EXT_RE.test(path) && !path.endsWith('.d.ts')).sort()) {
		const relative = file.slice(COMPONENTS_DIR.length);
		const directory = relative.includes('/') ? relative.slice(0, relative.lastIndexOf('/')) : '';
		const prefixParts = directory ? splitByCase(directory) : [];
		let fileName = relative.slice(relative.lastIndexOf('/') + 1).replace(COMPONENT_EXT_RE, '');
		const mode = (fileName.match(COMPONENT_MODE_RE)?.[1] ?? 'all') as ScannedComponent['mode'];
		fileName = fileName.replace(MODE_REPLACEMENT_RE, '');
		if (fileName.toLowerCase() === 'index') fileName = '';
		const pascalName = pascalCase(resolveComponentNameSegments(fileName.replace(/["']/g, ''), prefixParts));
		// Deux fichiers pour un même nom : Nuxt garde le premier et prévient.
		if (!pascalName || names.has(pascalName)) continue;
		names.add(pascalName);
		components.push({ pascalName, file, mode });
	}
	return components;
}

/** nuxt/dist (src/components/scan.ts) : le dossier sert de préfixe, sauf s'il est déjà au début du nom. */
function resolveComponentNameSegments(fileName: string, prefixParts: string[]): string[] {
	const fileNameParts = splitByCase(fileName);
	const fileNamePartsContent = fileNameParts.join('/').toLowerCase();
	const componentNameParts = prefixParts.flatMap((part) => splitByCase(part));
	let index = prefixParts.length - 1;
	const matchedSuffix: string[] = [];
	while (index >= 0) {
		const prefixPart = prefixParts[index];
		matchedSuffix.unshift(...splitByCase(prefixPart).map((part) => part.toLowerCase()));
		const matchedSuffixContent = matchedSuffix.join('/');
		if (fileNamePartsContent === matchedSuffixContent || fileNamePartsContent.startsWith(`${matchedSuffixContent}/`) || (prefixPart.toLowerCase() === fileNamePartsContent && prefixParts[index + 1] && prefixParts[index] === prefixParts[index + 1])) {
			componentNameParts.length = index;
		}
		index--;
	}
	return [...componentNameParts, ...fileNameParts];
}

// --- Auto-imports du projet ---------------------------------------------------------------

export interface ScannedImport {
	/** Nom sous lequel l'export est auto-importé. */
	as: string;
	/** Nom exporté (`default` pour un export par défaut). */
	name: string;
	file: string;
}

/** Dossiers auto-importés côté application (Nuxt) et côté serveur (Nitro). */
export const APP_IMPORT_DIRS = ['app/composables/', 'app/utils/', 'shared/utils/', 'shared/types/'];
export const SERVER_IMPORT_DIRS = ['server/utils/', 'shared/utils/', 'shared/types/'];
const IMPORT_EXT_RE = /\.(mts|cts|ts|tsx|mjs|cjs|js|jsx)$/;
const NAME_SEPARATOR_RE = /[-_.]/;

export function scanImports(files: Map<string, string>, directories = APP_IMPORT_DIRS): ScannedImport[] {
	const imports: ScannedImport[] = [];
	for (const directory of directories) {
		// Les fichiers du dossier, et le index de ses sous-dossiers directs.
		const scanned = [...files.keys()]
			.filter((path) => path.startsWith(directory) && IMPORT_EXT_RE.test(path) && !path.endsWith('.d.ts'))
			.filter((path) => {
				const parts = path.slice(directory.length).split('/');
				return parts.length === 1 || (parts.length === 2 && /^index\./.test(parts[1]));
			})
			.sort();
		for (const file of scanned) {
			imports.push(...scanExports(file, files.get(file)!));
		}
	}
	return imports;
}

/** Exports d'un module, lus sans l'exécuter (comme findExports de mlly). */
export function scanExports(file: string, code: string): ScannedImport[] {
	const imports: ScannedImport[] = [];
	const source = code.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/.*$/gm, '$1');
	if (/\bexport\s+default\b/.test(source)) {
		const parts = file.split('/');
		let name = parts.at(-1)!.replace(IMPORT_EXT_RE, '');
		if (name === 'index') name = parts.at(-2)!;
		imports.push({ as: NAME_SEPARATOR_RE.test(name) ? camelCase(name) : name, name: 'default', file });
	}
	for (const match of source.matchAll(/\bexport\s+(?:declare\s+)?(?:async\s+)?(?:function\*?|const|let|var|class)\s+([A-Za-z_$][\w$]*)/g)) {
		imports.push({ as: match[1], name: match[1], file });
	}
	for (const match of source.matchAll(/\bexport\s*\{([^}]*)\}/g)) {
		for (const specifier of match[1].split(',')) {
			const [, exported] = specifier.trim().replace(/^type\s+/, '').match(/^[\w$]+(?:\s+as\s+([\w$]+))?$/) ?? [];
			const name = exported ?? specifier.trim().split(/\s+/)[0];
			if (name && !specifier.trim().startsWith('type ') && name !== 'default') imports.push({ as: name, name, file });
		}
	}
	return imports;
}
