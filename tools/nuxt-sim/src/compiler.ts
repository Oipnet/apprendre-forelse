/**
 * Chargement des fichiers TypeScript du projet sans Vite : sucrase retire les types et transforme
 * les imports en CommonJS (sans vérifier les types, comme `nuxi dev`), puis le module est évalué
 * avec les auto-imports de Nuxt passés en variables.
 */
import { transform } from 'sucrase';

export type ProjectFiles = Map<string, string>;

/** Modules que Nuxt résout vers ses auto-imports côté serveur. */
const VIRTUAL_MODULES = new Set(['h3', '#imports', 'nitropack/runtime', '#nitro', 'nuxt/config']);
const EXTENSIONS = ['', '.ts', '.js', '.mjs', '.mts', '.vue', '/index.ts', '/index.js'];

export interface LoaderOptions {
	/** Paquets fournis tels quels, par nom d'import (vue, vue/server-renderer…). */
	packages?: Record<string, unknown>;
	/** Préparation d'un fichier avant sucrase (compilation d'un .vue, auto-imports) : rend un module ES. */
	transform?: (path: string, source: string) => string;
	/** Source d'un module qui n'est pas un fichier du projet (les méta d'une page : `page.vue?macro=true`). */
	virtual?: (path: string) => string | undefined;
	/** Modules remplacés, par chemin (un composant simulé par `mockComponent` dans un test). */
	overrides?: Map<string, Record<string, unknown>>;
}

export class ModuleLoader {
	private readonly cache = new Map<string, Record<string, unknown>>();

	constructor(
		private readonly files: ProjectFiles,
		private readonly autoImports: Record<string, unknown>,
		private readonly options: LoaderOptions = {},
	) {}

	load(path: string): Record<string, unknown> {
		const override = this.options.overrides?.get(path);
		if (override) {
			return override;
		}
		const cached = this.cache.get(path);
		if (cached) {
			return cached;
		}
		if (path.endsWith('.json')) {
			// Comme Vite : un fichier JSON s'importe par défaut, et ses clés de premier niveau par leur nom.
			const data = JSON.parse(this.files.get(path) ?? 'null') as unknown;
			const exports = { __esModule: true, ...(data && typeof data === 'object' && !Array.isArray(data) ? data : {}), default: data };
			this.cache.set(path, exports);
			return exports;
		}
		const module = { exports: {} as Record<string, unknown> };
		// Posé avant l'évaluation : deux fichiers qui s'importent mutuellement ne bouclent pas.
		this.cache.set(path, module.exports);
		try {
			this.evaluator(path, Function)(module.exports, (specifier: string) => this.require(specifier, path), module, ...Object.values(this.autoImports));
		} catch (error) {
			// Comme vite-node : un module dont l'évaluation échoue n'est pas gardé, il sera réévalué (et échouera de nouveau).
			this.cache.delete(path);
			throw error;
		}
		this.cache.set(path, module.exports);
		return module.exports;
	}

	/** Comme load(), avec `await` accepté au premier niveau du fichier (un fichier de test : `await setup()`). */
	async loadWithTopLevelAwait(path: string): Promise<Record<string, unknown>> {
		const module = { exports: {} as Record<string, unknown> };
		this.cache.set(path, module.exports);
		const AsyncFunction = (async () => {}).constructor as FunctionConstructor;
		await this.evaluator(path, AsyncFunction)(module.exports, (specifier: string) => this.require(specifier, path), module, ...Object.values(this.autoImports));
		return module.exports;
	}

	private evaluator(path: string, constructor: FunctionConstructor) {
		const source = this.files.get(path) ?? this.options.virtual?.(path);
		if (source === undefined) {
			throw new Error(`Fichier introuvable : ${path}`);
		}
		const prepared = this.options.transform ? this.options.transform(path, source) : source;
		const { code } = transform(prepared, { transforms: ['typescript', 'imports'], filePath: path, production: true });
		return new constructor('exports', 'require', 'module', ...Object.keys(this.autoImports), `${code}\n//# sourceURL=${path}`);
	}

	private require(specifier: string, from: string): unknown {
		const packages = this.options.packages ?? {};
		if (specifier in packages) {
			return packages[specifier];
		}
		if (VIRTUAL_MODULES.has(specifier)) {
			return this.autoImports;
		}
		const resolved = resolveProjectImport(this.files, specifier, from);
		if (resolved === null) {
			throw new Error(cannotFindModule(specifier, from));
		}
		if (resolved === undefined) {
			throw new Error(`Le simulateur ne fournit pas le paquet « ${specifier} » (importé par ${from})`);
		}
		return this.load(resolved);
	}
}

/**
 * Fichier du projet visé par un import relatif ou un alias de Nuxt 4 (`~/` et `@/` : app/ ;
 * `~~/` et `@@/` : la racine ; `#shared/` : shared/). `undefined` pour un paquet, `null` pour un fichier introuvable.
 */
export function resolveProjectImport(files: ProjectFiles, specifier: string, from: string): string | null | undefined {
	let base: string | undefined;
	if (specifier.startsWith('./') || specifier.startsWith('../')) {
		base = normalize(`${from.split('/').slice(0, -1).join('/')}/${specifier}`);
	} else if (specifier.startsWith('~~/') || specifier.startsWith('@@/')) {
		base = normalize(specifier.slice(3));
	} else if (specifier.startsWith('~/') || specifier.startsWith('@/')) {
		base = normalize(`app/${specifier.slice(2)}`);
	} else if (specifier.startsWith('#shared/')) {
		base = normalize(`shared/${specifier.slice('#shared/'.length)}`);
	}
	if (base === undefined) {
		return undefined;
	}
	return EXTENSIONS.map((extension) => base + extension).find((candidate) => files.has(candidate)) ?? null;
}

function normalize(path: string): string {
	const parts: string[] = [];
	for (const part of path.split('/')) {
		if (part === '..') parts.pop();
		else if (part !== '.' && part !== '') parts.push(part);
	}
	return parts.join('/');
}

/** Message de vite-node (le chargeur de `nuxi dev`) pour un import introuvable. Le projet est à la racine « / ». */
export function cannotFindModule(specifier: string, from: string): string {
	return `Cannot find module '${specifier}' imported from '/${from}'.\n\n- If you rely on tsconfig.json's "paths" to resolve modules, please install "vite-tsconfig-paths" plugin to handle module resolution.\n- Make sure you don't have relative aliases in your Vitest config. Use absolute paths instead. Read more: https://vitest.dev/guide/common-errors`;
}
