/**
 * Ce que Nuxt génère dans #build à partir de `app/` : les routes de `app/pages` (par unrouting, la même
 * bibliothèque que Nuxt), les layouts de `app/layouts` et le composant racine `app/app.vue`.
 */
import { buildTree, toVueRouter4 } from 'unrouting';
import { extractPageMeta } from './sfc.ts';

const PAGES_DIR = 'app/pages/';
const LAYOUTS_DIR = 'app/layouts/';
/** Racine fictive : unrouting attend des chemins absolus. */
const ROOT = '/';

export interface PageRoute {
	name?: string;
	path: string;
	file: string;
	/** Source de l'objet passé à definePageMeta, s'il y en a un. */
	meta?: string;
	children: PageRoute[];
}

export interface AppStructure {
	/** Le projet a un dossier app/pages (le routeur de pages de Nuxt est actif). */
	pages: boolean;
	app?: string;
	layouts: Record<string, string>;
	routes: PageRoute[];
}

export function hasApp(files: Iterable<string>): boolean {
	for (const file of files) {
		if (file === 'app/app.vue' || file.startsWith(PAGES_DIR)) return true;
	}
	return false;
}

export function scanApp(files: Map<string, string>): AppStructure {
	const paths = [...files.keys()];
	const pages = paths.filter((file) => file.startsWith(PAGES_DIR) && /\.(vue|tsx?|jsx?)$/.test(file));
	const tree = buildTree(pages.map((file) => ({ path: ROOT + file, priority: 0 })), { roots: [ROOT + PAGES_DIR], modes: ['client'] });
	const toRoutes = (routes: { name?: string; path: string; file?: string; children?: unknown[]; meta?: Record<string, unknown> }[]): PageRoute[] =>
		routes.map((route) => {
			const file = route.file!.slice(ROOT.length);
			return {
				name: route.name,
				path: route.path,
				file,
				meta: extractPageMeta(files.get(file) ?? ''),
				children: toRoutes((route.children ?? []) as never),
			};
		});
	const layouts: Record<string, string> = {};
	for (const file of paths.filter((path) => path.startsWith(LAYOUTS_DIR) && path.endsWith('.vue'))) {
		const name = file.slice(LAYOUTS_DIR.length, -'.vue'.length).replace(/\/index$/, '').split('/').join('-');
		layouts[name.replace(/([a-z0-9])([A-Z])/g, '$1-$2').toLowerCase()] = file;
	}
	return {
		pages: pages.length > 0,
		app: files.has('app/app.vue') ? 'app/app.vue' : undefined,
		layouts,
		routes: toRoutes(toVueRouter4(tree, { attrs: { mode: ['client'] } }) as never),
	};
}
