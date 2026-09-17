/** Découverte des routes de `server/api` et `server/routes`, comme `scanServerRoutes` de Nitro 2. */

const SCANNED_EXTENSIONS = /\.(js|mjs|cjs|ts|mts|cts|tsx|jsx)$/;
const SUFFIX_RE = /(\.(?<method>connect|delete|get|head|options|patch|post|put|trace))?(\.(?<env>dev|prod|prerender))?$/;

export interface ServerRoute {
	/** Chemin du fichier dans le projet (server/api/ports/[id].get.ts). */
	file: string;
	/** Route au format radix3 (/api/ports/:id). */
	route: string;
	method?: string;
	env?: string;
}

export function scanServerRoutes(files: string[]): ServerRoute[] {
	const routes = [...scanDirectory(files, 'server/api', '/api'), ...scanDirectory(files, 'server/routes', '/')];
	// Une même route (chemin, méthode, environnement) déclarée deux fois : Nitro garde la première.
	return routes
		.filter((route, index) => routes.findIndex((other) => other.route === route.route && other.method === route.method && other.env === route.env) === index)
		// `nuxi dev` : les fichiers .prod et .prerender ne sont pas servis.
		.filter((route) => !route.env || route.env === 'dev');
}

function scanDirectory(files: string[], directory: string, prefix: string): ServerRoute[] {
	return files
		.filter((file) => file.startsWith(`${directory}/`) && SCANNED_EXTENSIONS.test(file))
		.map((file) => ({ file, path: file.slice(directory.length + 1) }))
		.sort((a, b) => a.path.localeCompare(b.path))
		.map(({ file, path }) => {
			let route = path
				.replace(/\.[A-Za-z]+$/, '')
				.replace(/\(([^(/\\]+)\)[/\\]/g, '')
				.replace(/\[\.{3}]/g, '**')
				.replace(/\[\.{3}(\w+)]/g, '**:$1')
				.replace(/\[([^/\]]+)]/g, ':$1');
			route = withLeadingSlash(withoutTrailing(joinBase(route, prefix)));
			const suffix = route.match(SUFFIX_RE);
			let method: string | undefined;
			let env: string | undefined;
			if (suffix?.index && suffix.index >= 0) {
				route = route.slice(0, suffix.index);
				method = suffix.groups?.method;
				env = suffix.groups?.env;
			}
			route = route.replace(/\/index$/, '') || '/';
			return { file, route, method, env };
		});
}

function joinBase(input: string, base: string): string {
	if (!base || base === '/') {
		return input;
	}
	const trimmedBase = base.replace(/\/$/, '');
	const nextChar = input[trimmedBase.length];
	if (input.startsWith(trimmedBase) && (!nextChar || nextChar === '/' || nextChar === '?')) {
		return input;
	}
	return `${trimmedBase}/${input.replace(/^\/+/, '')}`;
}

function withoutTrailing(input: string): string {
	return input.endsWith('/') ? input.slice(0, -1) || '/' : input;
}

function withLeadingSlash(input: string): string {
	return input.startsWith('/') ? input : `/${input}`;
}
