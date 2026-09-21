/**
 * Macros d'un fichier de test, réécrites avant l'évaluation comme le font Vitest (hoistMocks) et le
 * plugin `nuxt:vitest:mock-transform` de @nuxt/test-utils :
 *
 * - les `vi.hoisted(…)` montent en tête du fichier, juste après les imports de `vitest` ;
 * - `mockNuxtImport(nom, fabrique)`, `unmockNuxtImport(nom)` et `mockComponent(nom, fabrique)` sont retirés
 *   de leur place (où qu'ils soient) et deviennent un appel à `__nuxtMocks`, en tête lui aussi : la
 *   fabrique est appelée avant que le fichier n'importe quoi d'autre, avec la fonction d'origine.
 *
 * Le code reçu est un module ES sans types (sucrase est passé avant).
 */
import { parse, type Node } from 'acorn';

const HELPERS = ['mockNuxtImport', 'unmockNuxtImport', 'mockComponent'];

export class MacroError extends Error {}

/** Le nom de la fonction que le préambule appelle ; le chargeur le fournit comme import de `@nuxt/test-utils/runtime`. */
export const MOCKS_HELPER = '__nuxtMocks';

export interface MacroOptions {
	/** Auto-imports connus : mockNuxtImport refuse un nom qui n'en est pas un. */
	isImport(name: string): boolean;
}

export function transformTestMacros(code: string, options: MacroOptions): string {
	if (!HELPERS.some((name) => code.includes(name)) && !code.includes('vi.hoisted')) return code;
	const ast = parse(code, { ecmaVersion: 'latest', sourceType: 'module', allowAwaitOutsideFunction: true }) as unknown as AnyNode;
	const removals: { start: number; end: number }[] = [];
	const vitestImports: AnyNode[] = [];
	const hoisted: string[] = [];
	const imports: string[] = [];
	const unmocks: string[] = [];
	const components: string[] = [];

	for (const statement of ast.body) {
		if (statement.type === 'ImportDeclaration' && statement.source.value === 'vitest') {
			vitestImports.push(statement);
			removals.push(statement);
			continue;
		}
		// `vi.hoisted(…)` seul, ou dans une déclaration (`const { x } = vi.hoisted(…)`, avec ou sans await).
		const declared = statement.type === 'VariableDeclaration' ? statement.declarations.map((d: AnyNode) => unwrapAwait(d.init)) : [];
		const expression = statement.type === 'ExpressionStatement' ? unwrapAwait(statement.expression) : undefined;
		if ([expression, ...declared].some((node) => isViCall(node, 'hoisted'))) {
			hoisted.push(code.slice(statement.start, statement.end));
			removals.push(statement);
		}
	}

	walk(ast, (node, parent) => {
		if (node.type !== 'CallExpression' || node.callee.type !== 'Identifier' || !HELPERS.includes(node.callee.name)) return;
		const helper = node.callee.name as string;
		const range = parent?.type === 'ExpressionStatement' ? parent : node;
		const [target, factory] = node.arguments as AnyNode[];
		const expected = helper === 'unmockNuxtImport' ? 1 : 2;
		if (node.arguments.length !== expected) {
			throw new MacroError(`${helper}() should have exactly ${expected} argument${expected > 1 ? 's' : ''}`);
		}
		if (helper === 'mockComponent') {
			if (target.type !== 'Literal' || typeof target.value !== 'string') throw new MacroError('The first argument of mockComponent() must be a string literal');
			components.push(`[${JSON.stringify(target.value)}, ${code.slice(factory.start, factory.end)}]`);
		} else {
			const name = target.type === 'Literal' ? target.value : target.type === 'Identifier' ? target.name : undefined;
			if (typeof name !== 'string') throw new MacroError(`The first argument of ${helper}() must be a string literal or mocked target`);
			if (!options.isImport(name)) throw new MacroError(`Cannot find import "${name}" to ${helper === 'mockNuxtImport' ? 'mock' : 'unmock'}`);
			if (helper === 'mockNuxtImport') imports.push(`[${JSON.stringify(name)}, ${code.slice(factory.start, factory.end)}]`);
			else unmocks.push(JSON.stringify(name));
		}
		removals.push(range);
		return false;
	});

	if (removals.length === 0) return code;
	let body = '';
	let last = 0;
	for (const { start, end } of removals.sort((a, b) => a.start - b.start)) {
		if (start < last) continue;
		body += code.slice(last, start);
		last = end;
	}
	body += code.slice(last);
	const preamble = [...vitestImports.map((node) => code.slice(node.start, node.end)), ...hoisted];
	if (imports.length || unmocks.length || components.length) {
		preamble.push(
			`import { ${MOCKS_HELPER} } from '@nuxt/test-utils/runtime';`,
			`await ${MOCKS_HELPER}([${imports.join(', ')}], [${unmocks.join(', ')}], [${components.join(', ')}]);`,
		);
	}
	return `${preamble.join('\n')}\n${body}`;
}

type AnyNode = Node & Record<string, any>;

function unwrapAwait(node: AnyNode | null | undefined): AnyNode | undefined {
	return node?.type === 'AwaitExpression' ? node.argument : node ?? undefined;
}

function isViCall(node: AnyNode | undefined, method: string): boolean {
	return node?.type === 'CallExpression' && node.callee.type === 'MemberExpression' && node.callee.object.type === 'Identifier'
		&& node.callee.object.name === 'vi' && node.callee.property.type === 'Identifier' && node.callee.property.name === method;
}

/** Parcours en profondeur ; `false` rendu par `visit` n'explore pas les enfants du nœud. */
function walk(node: AnyNode, visit: (node: AnyNode, parent?: AnyNode) => boolean | void, parent?: AnyNode): void {
	if (visit(node, parent) === false) return;
	for (const key of Object.keys(node)) {
		if (key === 'type' || key === 'start' || key === 'end') continue;
		const value = node[key];
		if (Array.isArray(value)) {
			for (const child of value) if (child && typeof child.type === 'string') walk(child, visit, node);
		} else if (value && typeof value.type === 'string') {
			walk(value, visit, node);
		}
	}
}
