/**
 * Environnement de chaque fichier de test (`node` ou `nuxt`), comme Vitest le décide : commentaire
 * `@vitest-environment` en tête du fichier, sinon le projet de vitest.config.ts dont `include` couvre le
 * fichier. `defineVitestConfig` de @nuxt/test-utils répartit lui-même les fichiers : `*.nuxt.test.ts` et
 * `test(s)/nuxt/**` dans l'environnement `nuxt`, le reste dans celui de la configuration (`node`).
 */
import { ModuleLoader, type ProjectFiles } from '../compiler.ts';

export type TestEnvironment = 'node' | 'nuxt';

const CONFIG_FILES = ['vitest.config.ts', 'vitest.config.mts', 'vitest.config.js', 'vitest.config.mjs'];
/** `configDefaults.include` de Vitest. */
const DEFAULT_INCLUDE = ['**/*.{test,spec}.?(c|m)[jt]s?(x)'];
const DEFAULT_EXCLUDE = ['**/node_modules/**', '**/.git/**'];
const EXTENSIONS = '{js,mjs,cjs,ts,mts,cts,jsx,tsx}';
/** Ce que `defineVitestConfig` range dans le projet `nuxt`. */
const NUXT_INCLUDE = [`**/*.nuxt.{test,spec}.${EXTENSIONS}`, `{test,tests}/nuxt/**/*.{test,spec}.${EXTENSIONS}`];
const VITEST_CONFIG = Symbol('defineVitestConfig');

interface Project {
	environment: string;
	include: string[];
	exclude: string[];
}

/** Environnement de chaque fichier de test, par chemin. */
export async function resolveEnvironments(files: ProjectFiles, paths: string[]): Promise<Map<string, TestEnvironment>> {
	const projects = await readProjects(files);
	return new Map(paths.map((path) => {
		const docblock = docblockEnvironment(files.get(path) ?? '');
		const project = projects.find((candidate) => matchesAny(path, candidate.include) && !matchesAny(path, candidate.exclude));
		return [path, (docblock ?? project?.environment) === 'nuxt' ? 'nuxt' : 'node'];
	}));
}

/** `// @vitest-environment nuxt` ou `/** @vitest-environment nuxt *\/` avant le code. */
function docblockEnvironment(source: string): string | undefined {
	const header = source.match(/^(?:\s*(?:\/\/[^\n]*|\/\*[\s\S]*?\*\/))+/)?.[0] ?? '';
	return header.match(/@(?:vitest|jest)-environment\s+([\w-]+)/)?.[1];
}

async function readProjects(files: ProjectFiles): Promise<Project[]> {
	const path = CONFIG_FILES.find((candidate) => files.has(candidate));
	if (!path) return [{ environment: 'node', include: DEFAULT_INCLUDE, exclude: DEFAULT_EXCLUDE }];
	const loader = new ModuleLoader(files, {}, {
		packages: {
			'vitest/config': { defineConfig: (config: unknown) => config, defineProject: (config: unknown) => config, configDefaults: { include: DEFAULT_INCLUDE, exclude: DEFAULT_EXCLUDE } },
			'@nuxt/test-utils/config': {
				defineVitestConfig: (config: Record<string, any> = {}) => ({ ...config, [VITEST_CONFIG]: true }),
				defineVitestProject: async (config: Record<string, any> = {}) => ({ ...config, test: { environment: 'nuxt', ...config.test } }),
			},
			'node:url': { fileURLToPath: (url: string | URL) => String(url).replace(/^file:\/\//, '') },
			url: { fileURLToPath: (url: string | URL) => String(url).replace(/^file:\/\//, '') },
		},
		// `import.meta.url` (rootDir de la configuration) n'a pas de sens ici : la racine du projet.
		transform: (_path, source) => source.replace(/\bimport\.meta\.url\b/g, JSON.stringify('file:///')),
	});
	let config = (await loader.loadWithTopLevelAwait(path)).default as any;
	if (typeof config === 'function') config = await config({ mode: 'test', command: 'serve' });
	config = await config;
	const test = config?.test ?? {};
	if (Array.isArray(test.projects)) {
		const projects = await Promise.all(test.projects.map(async (entry: any) => (await entry)?.test ?? {}));
		return projects.map((project) => toProject(project));
	}
	const environment = test.environment || 'node';
	if (config?.[VITEST_CONFIG] && environment !== 'nuxt') {
		return [
			{ environment: 'nuxt', include: NUXT_INCLUDE, exclude: DEFAULT_EXCLUDE },
			{ environment, include: test.include ?? DEFAULT_INCLUDE, exclude: [...(test.exclude ?? DEFAULT_EXCLUDE), ...NUXT_INCLUDE] },
		];
	}
	return [toProject({ ...test, environment })];
}

function toProject(test: Record<string, any>): Project {
	return { environment: test.environment || 'node', include: test.include ?? DEFAULT_INCLUDE, exclude: test.exclude ?? DEFAULT_EXCLUDE };
}

function matchesAny(path: string, patterns: string[]): boolean {
	return patterns.some((pattern) => globToRegExp(pattern).test(path));
}

/** Motifs de Vitest (picomatch) : `**`, `*`, `?`, `[…]`, `{a,b}`, `?(a|b)`. */
export function globToRegExp(glob: string): RegExp {
	let source = '';
	for (let i = 0; i < glob.length; i++) {
		const char = glob[i];
		if (char === '*' && glob[i + 1] === '*') {
			const slash = glob[i + 2] === '/';
			source += slash ? '(?:.*/)?' : '.*';
			i += slash ? 2 : 1;
		} else if (char === '*') {
			source += '[^/]*';
		} else if (char === '?' && glob[i + 1] === '(') {
			const end = glob.indexOf(')', i);
			source += `(?:${glob.slice(i + 2, end).split('|').map(escapeRegExp).join('|')})?`;
			i = end;
		} else if (char === '?') {
			source += '[^/]';
		} else if (char === '[') {
			const end = glob.indexOf(']', i);
			source += glob.slice(i, end + 1);
			i = end;
		} else if (char === '{') {
			const end = glob.indexOf('}', i);
			source += `(?:${glob.slice(i + 1, end).split(',').map((part) => globToRegExp(part).source.slice(1, -1)).join('|')})`;
			i = end;
		} else {
			source += escapeRegExp(char);
		}
	}
	return new RegExp(`^${source}$`);
}

function escapeRegExp(text: string): string {
	return text.replace(/[.+^$()|\\]/g, '\\$&');
}
