/**
 * Routeur de `radix3` (unjs), utilisé par le routeur de h3 1.x : priorité aux routes statiques,
 * puis aux paramètres (`:id`), et en dernier recours au joker (`**`). Portage fidèle,
 * y compris ses bizarreries (choix entre plusieurs paramètres selon la profondeur restante).
 */

const enum NodeType {
	Normal = 0,
	Wildcard = 1,
	Placeholder = 2,
}

interface RadixNode<T> {
	type: NodeType;
	maxDepth: number;
	parent: RadixNode<T> | null;
	children: Map<string, RadixNode<T>>;
	data: T | null;
	paramName: string | null;
	wildcardChildNode: RadixNode<T> | null;
	placeholderChildren: RadixNode<T>[];
}

export type MatchedRoute<T> = T & { params?: Record<string, string> };

function createNode<T>(type = NodeType.Normal, parent: RadixNode<T> | null = null): RadixNode<T> {
	return { type, maxDepth: 0, parent, children: new Map(), data: null, paramName: null, wildcardChildNode: null, placeholderChildren: [] };
}

function nodeType(section: string): NodeType {
	if (section.startsWith('**')) return NodeType.Wildcard;
	if (section[0] === ':' || section === '*') return NodeType.Placeholder;
	return NodeType.Normal;
}

const normalizeTrailingSlash = (path: string) => path.replace(/\/$/, '') || '/';

export class RadixRouter<T extends object> {
	private readonly root = createNode<T>();
	private readonly staticRoutes = new Map<string, RadixNode<T>>();

	insert(rawPath: string, data: T): void {
		const path = normalizeTrailingSlash(rawPath);
		let isStatic = true;
		let node = this.root;
		let unnamed = 0;
		const matched = [node];
		for (const section of path.split('/')) {
			const existing = node.children.get(section);
			if (existing) {
				node = existing;
				continue;
			}
			const type = nodeType(section);
			const child = createNode<T>(type, node);
			node.children.set(section, child);
			if (type === NodeType.Placeholder) {
				child.paramName = section === '*' ? `_${unnamed++}` : section.slice(1);
				node.placeholderChildren.push(child);
				isStatic = false;
			} else if (type === NodeType.Wildcard) {
				node.wildcardChildNode = child;
				child.paramName = section.slice(3) || '_';
				isStatic = false;
			}
			matched.push(child);
			node = child;
		}
		for (const [depth, current] of matched.entries()) {
			current.maxDepth = Math.max(matched.length - depth, current.maxDepth || 0);
		}
		node.data = data;
		if (isStatic) {
			this.staticRoutes.set(path, node);
		}
	}

	lookup(rawPath: string): MatchedRoute<T> | null {
		const path = normalizeTrailingSlash(rawPath);
		const staticNode = this.staticRoutes.get(path);
		if (staticNode) {
			return staticNode.data;
		}
		const sections = path.split('/');
		const params: Record<string, string> = {};
		let paramsFound = false;
		let wildcardNode: RadixNode<T> | null = null;
		let wildcardParam: string | null = null;
		let node: RadixNode<T> | null = this.root;
		for (let i = 0; i < sections.length; i++) {
			const section = sections[i];
			if (node!.wildcardChildNode !== null) {
				wildcardNode = node!.wildcardChildNode;
				wildcardParam = sections.slice(i).join('/');
			}
			const next: RadixNode<T> | undefined = node!.children.get(section);
			if (next === undefined) {
				if (node!.placeholderChildren.length > 1) {
					const remaining = sections.length - i;
					node = node!.placeholderChildren.find((child) => child.maxDepth === remaining) || null;
				} else {
					node = node!.placeholderChildren[0] || null;
				}
				if (!node) {
					break;
				}
				if (node.paramName) {
					params[node.paramName] = section;
				}
				paramsFound = true;
			} else {
				node = next;
			}
		}
		if ((node === null || node.data === null) && wildcardNode !== null) {
			node = wildcardNode;
			params[node.paramName || '_'] = wildcardParam!;
			paramsFound = true;
		}
		if (!node || node.data === null) {
			return node ? (node.data as null) : null;
		}
		return paramsFound ? { ...node.data, params } : node.data;
	}

	/** Toutes les routes qui correspondent, de la moins précise à la plus précise (`toRouteMatcher().matchAll`). */
	matchAll(path: string): T[] {
		return matchRoutes(path, this.toTable('', this.root));
	}

	private toTable(initialPath: string, initialNode: RadixNode<T>): RouteTable<T> {
		const table: RouteTable<T> = { static: new Map(), wildcard: new Map(), dynamic: new Map() };
		const addNode = (path: string, node: RadixNode<T>) => {
			if (path) {
				if (node.type === NodeType.Normal && !(path.includes('*') || path.includes(':'))) {
					if (node.data) table.static.set(path, node.data);
				} else if (node.type === NodeType.Wildcard) {
					table.wildcard.set(path.replace('/**', ''), node.data);
				} else if (node.type === NodeType.Placeholder) {
					const subTable = this.toTable('', node);
					if (node.data) subTable.static.set('/', node.data);
					table.dynamic.set(path.replace(/\/\*|\/:\w+/, ''), subTable);
					return;
				}
			}
			for (const [childPath, child] of node.children.entries()) {
				addNode(`${path}/${childPath}`.replace('//', '/'), child);
			}
		};
		addNode(initialPath, initialNode);
		return table;
	}
}

interface RouteTable<T> {
	static: Map<string, T>;
	wildcard: Map<string, T | null>;
	dynamic: Map<string, RouteTable<T>>;
}

function matchRoutes<T>(rawPath: string, table: RouteTable<T>): T[] {
	const path = rawPath.endsWith('/') ? rawPath.slice(0, -1) || '/' : rawPath;
	const matches: (T | null)[] = [];
	for (const [key, value] of [...table.wildcard.entries()].sort((a, b) => a[0].length - b[0].length)) {
		if (path === key || path.startsWith(`${key}/`)) matches.push(value);
	}
	for (const [key, value] of [...table.dynamic.entries()].sort((a, b) => a[0].length - b[0].length)) {
		if (path.startsWith(`${key}/`)) {
			const subPath = `/${path.slice(key.length).split('/').splice(2).join('/')}`;
			matches.push(...matchRoutes(subPath, value));
		}
	}
	const staticMatch = table.static.get(path);
	if (staticMatch) matches.push(staticMatch);
	return matches.filter((match): match is T => Boolean(match));
}
