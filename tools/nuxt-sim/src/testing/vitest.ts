/**
 * Le module `vitest` vu par les tests d'un exercice : describe/it et leurs hooks, `expect` et `vi`.
 *
 * `expect` et `vi` sont ceux de Vitest (@vitest/expect, @vitest/spy : mêmes assertions, mêmes messages).
 * Le runner, lui, est écrit ici : Vitest le lie à Node (fichiers, workers). Il suit son ordre d'exécution
 * (beforeAll, puis pour chaque test les beforeEach de l'extérieur vers l'intérieur et les afterEach en
 * sens inverse, afterAll), `.only` et `.skip` à l'échelle du fichier, les délais de 5 s (tests) et 10 s
 * (hooks). Pas de snapshots, de `vi.mock` ni de tests concurrents.
 */
import {
	ASYMMETRIC_MATCHERS_OBJECT, ChaiStyleAssertions, GLOBAL_EXPECT, JestAsymmetricMatchers, JestChaiExpect, JestExtend,
	addCustomEqualityTesters, chai, getState, setState,
} from '@vitest/expect';
import { clearAllMocks, fn, isMockFunction, resetAllMocks, restoreAllMocks, spyOn } from '@vitest/spy';
import type { TestCaseResult } from './types.ts';

chai.use(JestExtend);
chai.use(JestChaiExpect);
chai.use(ChaiStyleAssertions);
chai.use(JestAsymmetricMatchers);

const TEST_TIMEOUT = 5_000;
const HOOK_TIMEOUT = 10_000;

type Callback = () => unknown;
type Mode = 'run' | 'skip' | 'only' | 'todo';

interface TestNode {
	type: 'test';
	name: string;
	fn?: Callback;
	mode: Mode;
	timeout: number;
}

interface SuiteNode {
	type: 'suite';
	name: string;
	mode: Mode;
	/** Callback du `describe`, appelé à la collecte (après le chargement du fichier), pas à la déclaration. */
	factory?: Callback;
	children: (TestNode | SuiteNode)[];
	beforeAll: Callback[];
	afterAll: Callback[];
	beforeEach: Callback[];
	afterEach: Callback[];
}

function createSuite(name: string, mode: Mode): SuiteNode {
	return { type: 'suite', name, mode, children: [], beforeAll: [], afterAll: [], beforeEach: [], afterEach: [] };
}

// --- expect, comme createExpect() de Vitest -------------------------------------------------

function createExpect() {
	const expect = ((value: unknown, message?: string) => {
		const { assertionCalls } = getState(expect);
		setState({ assertionCalls: assertionCalls + 1 }, expect);
		return chai.expect(value, message);
	}) as any;
	Object.assign(expect, chai.expect);
	Object.assign(expect, (globalThis as any)[ASYMMETRIC_MATCHERS_OBJECT]);
	expect.getState = () => getState(expect);
	expect.setState = (state: object) => setState(state, expect);
	setState({ assertionCalls: 0, isExpectingAssertions: false, isExpectingAssertionsError: null, expectedAssertionsNumber: null, expectedAssertionsNumberErrorGen: null, currentTestName: '' }, expect);
	expect.assert = chai.assert;
	expect.extend = (matchers: object) => (chai.expect as any).extend(expect, matchers);
	expect.addEqualityTesters = addCustomEqualityTesters;
	expect.soft = (...args: unknown[]) => expect(...args).withContext({ soft: true });
	expect.unreachable = (message?: string) => chai.assert.fail(`expected${message ? ` "${message}" ` : ' '}not to be reached`);
	chai.util.addMethod(expect, 'assertions', (expected: number) => {
		expect.setState({ expectedAssertionsNumber: expected, expectedAssertionsNumberErrorGen: () => new Error(`expected number of assertions to be ${expected}, but got ${expect.getState().assertionCalls}`) });
	});
	chai.util.addMethod(expect, 'hasAssertions', () => {
		expect.setState({ isExpectingAssertions: true, isExpectingAssertionsError: new Error('expected any number of assertion, but got none') });
	});
	(globalThis as any)[GLOBAL_EXPECT] = expect;
	return expect;
}

// --- Collecte ------------------------------------------------------------------------------

/** Un fichier de test : l'API `vitest` qu'il importe, et l'arbre de ses suites une fois évalué. */
export class TestFile {
	readonly root = createSuite('', 'run');
	readonly expect = createExpect();
	private current = this.root;

	constructor(readonly path: string) {}

	/** Ce que `import { … } from 'vitest'` renvoie. */
	api(): Record<string, unknown> {
		const describe = this.chain((name, fn, mode) => this.describe(name, fn, mode));
		const it = this.chain((name, fn, mode, timeout) => this.test(name, fn, mode, timeout));
		const hook = (kind: 'beforeAll' | 'afterAll' | 'beforeEach' | 'afterEach') => (callback: Callback) => void this.current[kind].push(callback);
		return {
			describe, suite: describe, it, test: it,
			beforeAll: hook('beforeAll'), afterAll: hook('afterAll'), beforeEach: hook('beforeEach'), afterEach: hook('afterEach'),
			expect: this.expect, assert: chai.assert,
			vi: this.vi(),
		};
	}

	/** Globales remplacées par `vi.stubGlobal`, avec leur descripteur d'origine. */
	private readonly stubbedGlobals = new Map<string, PropertyDescriptor | undefined>();

	private vi() {
		const utils: Record<string, unknown> = {
			fn, spyOn, isMockFunction, clearAllMocks, resetAllMocks, restoreAllMocks,
			/** Hissé en tête du fichier par le chargeur (voir nuxt/macros.ts) : la fabrique est appelée sur place. */
			hoisted: (factory: () => unknown) => factory(),
			mocked: (item: unknown) => item,
			waitFor,
			waitUntil,
			stubGlobal: (name: string, value: unknown) => {
				if (!this.stubbedGlobals.has(name)) this.stubbedGlobals.set(name, Object.getOwnPropertyDescriptor(globalThis, name));
				Object.defineProperty(globalThis, name, { value, writable: true, configurable: true, enumerable: true });
				return utils;
			},
			unstubAllGlobals: () => {
				this.unstubAllGlobals();
				return utils;
			},
			mock: () => {
				throw new Error('vi.mock n\'est pas disponible dans le simulateur : pour une fonction de Nuxt, utilisez mockNuxtImport (@nuxt/test-utils/runtime).');
			},
		};
		return utils;
	}

	/** Fin du fichier : Vitest isole chaque fichier, le simulateur remet les globales remplacées. */
	unstubAllGlobals(): void {
		for (const [name, original] of this.stubbedGlobals) {
			if (!original) Reflect.deleteProperty(globalThis, name);
			else Object.defineProperty(globalThis, name, original);
		}
		this.stubbedGlobals.clear();
	}

	/** Collecte, comme Vitest : les callbacks des `describe`, dans l'ordre, chacun attendu avant le suivant. */
	async collected(suite = this.root): Promise<void> {
		for (let i = 0; i < suite.children.length; i++) {
			const child = suite.children[i];
			if (child.type !== 'suite') continue;
			const factory = child.factory;
			child.factory = undefined;
			if (factory) {
				const parent = this.current;
				this.current = child;
				try {
					await factory();
				} finally {
					this.current = parent;
				}
			}
			await this.collected(child);
		}
	}

	private describe(name: string, callback: Callback | undefined, mode: Mode): void {
		const suite = createSuite(name, mode);
		suite.factory = callback;
		this.current.children.push(suite);
	}

	private test(name: string, callback: Callback | undefined, mode: Mode, timeout?: number): void {
		this.current.children.push({ type: 'test', name, fn: callback, mode: callback ? mode : 'todo', timeout: timeout ?? TEST_TIMEOUT });
	}

	/** `it`, `it.skip`, `it.only`, `it.todo`, `it.skipIf(c)`, `it.runIf(c)`, `it.each(table)`… */
	private chain(register: (name: string, fn: Callback | undefined, mode: Mode, timeout?: number) => void) {
		const make = (mode: Mode) => {
			const call = (name: string, fnOrOptions?: Callback | { timeout?: number }, maybeFn?: Callback | number) => {
				const callback = typeof fnOrOptions === 'function' ? fnOrOptions : typeof maybeFn === 'function' ? maybeFn : undefined;
				const timeout = typeof maybeFn === 'number' ? maybeFn : typeof fnOrOptions === 'object' ? fnOrOptions?.timeout : undefined;
				register(String(name), callback, mode, timeout);
			};
			return Object.assign(call, {
				each: (table: unknown[]) => (name: string, callback: (...args: unknown[]) => unknown, timeout?: number) => {
					table.forEach((row, index) => {
						const args = Array.isArray(row) ? row : [row];
						register(formatTitle(name, args, index), () => callback(...args), mode, timeout);
					});
				},
			});
		};
		const run = make('run');
		return Object.assign(run, {
			skip: make('skip'),
			only: make('only'),
			todo: (name: string) => register(String(name), undefined, 'todo'),
			skipIf: (condition: unknown) => make(condition ? 'skip' : 'run'),
			runIf: (condition: unknown) => make(condition ? 'run' : 'skip'),
			concurrent: run,
			sequential: run,
		});
	}

	// --- Exécution ---------------------------------------------------------------------------

	async run(): Promise<TestCaseResult[]> {
		const results: TestCaseResult[] = [];
		const hasOnly = containsOnly(this.root);
		await this.runSuite(this.root, [], [], [], hasOnly, false, results);
		return results;
	}

	private async runSuite(suite: SuiteNode, path: string[], beforeEach: Callback[], afterEach: Callback[], hasOnly: boolean, parentOnly: boolean, results: TestCaseResult[]): Promise<void> {
		const inOnly = parentOnly || suite.mode === 'only';
		const skipped = suite.mode === 'skip' || suite.mode === 'todo';
		const names = suite === this.root ? path : [...path, suite.name];
		const runnable = !skipped && hasRunnableTest(suite, hasOnly, inOnly);
		let setupError: unknown;
		if (runnable) {
			for (const hook of suite.beforeAll) {
				try {
					await withTimeout(hook(), HOOK_TIMEOUT, 'beforeAll');
				} catch (error) {
					setupError = error;
					break;
				}
			}
		}
		const allBeforeEach = [...beforeEach, ...suite.beforeEach];
		const allAfterEach = [...suite.afterEach, ...afterEach];
		for (const child of suite.children) {
			if (child.type === 'suite') {
				if (skipped) child.mode = 'skip';
				await this.runSuite(child, names, allBeforeEach, allAfterEach, hasOnly, inOnly, results);
				continue;
			}
			const base = { className: names.join(' > '), name: child.name, file: this.path };
			const selected = !hasOnly || inOnly || child.mode === 'only';
			if (skipped || !selected || child.mode === 'skip' || child.mode === 'todo') {
				results.push({ ...base, status: 'skipped', timeMs: 0 });
				continue;
			}
			if (setupError !== undefined) {
				// Vitest ignore les tests d'une suite dont le beforeAll échoue, et signale l'erreur sur la suite.
				results.push({ ...base, status: 'skipped', message: `beforeAll : ${messageOf(setupError)}`, timeMs: 0 });
				continue;
			}
			results.push({ ...base, ...(await this.runTest(child, allBeforeEach, allAfterEach, [...names, child.name].join(' > '))) });
		}
		if (runnable) {
			for (const hook of suite.afterAll) {
				try {
					await withTimeout(hook(), HOOK_TIMEOUT, 'afterAll');
				} catch (error) {
					const last = results.at(-1);
					if (last && last.status === 'passed') Object.assign(last, { status: 'error', message: `afterAll : ${messageOf(error)}` });
				}
			}
		}
	}

	private async runTest(test: TestNode, beforeEach: Callback[], afterEach: Callback[], fullName: string): Promise<Pick<TestCaseResult, 'status' | 'message' | 'timeMs'>> {
		const started = performance.now();
		this.expect.setState({ assertionCalls: 0, isExpectingAssertions: false, isExpectingAssertionsError: null, expectedAssertionsNumber: null, expectedAssertionsNumberErrorGen: null, currentTestName: fullName });
		const cleanups: Callback[] = [];
		let failure: unknown;
		try {
			for (const hook of beforeEach) {
				const cleanup = await withTimeout(hook(), HOOK_TIMEOUT, 'beforeEach');
				if (typeof cleanup === 'function') cleanups.unshift(cleanup as Callback);
			}
			await withTimeout(test.fn!(), test.timeout, 'test');
			const state = this.expect.getState();
			if (state.expectedAssertionsNumber !== null && state.assertionCalls !== state.expectedAssertionsNumber) throw state.expectedAssertionsNumberErrorGen();
			if (state.isExpectingAssertions && state.assertionCalls === 0) throw state.isExpectingAssertionsError;
		} catch (error) {
			failure = error;
		}
		// Vitest : les afterEach (de l'intérieur vers l'extérieur), puis les nettoyages rendus par les beforeEach.
		for (const hook of [...afterEach, ...cleanups]) {
			try {
				await withTimeout(hook(), HOOK_TIMEOUT, 'afterEach');
			} catch (error) {
				failure ??= error;
			}
		}
		const timeMs = Math.round(performance.now() - started);
		if (failure === undefined) {
			return { status: 'passed', timeMs };
		}
		return { status: isAssertion(failure) ? 'failed' : 'error', message: messageOf(failure), timeMs };
	}
}

/** `vi.waitFor` de Vitest (sans faux minuteurs) : rappelle `callback` jusqu'à ce qu'il ne lève plus. */
function waitFor<T>(callback: () => T | Promise<T>, options: number | { timeout?: number; interval?: number } = {}): Promise<T> {
	const { interval = 50, timeout = 1000 } = typeof options === 'number' ? { timeout: options } : options;
	return new Promise((resolve, reject) => {
		let lastError: unknown;
		let promiseStatus = 'idle';
		let timeoutId: ReturnType<typeof setTimeout> | undefined;
		let intervalId: ReturnType<typeof setInterval> | undefined;
		const onResolve = (result: T) => {
			clearTimeout(timeoutId);
			clearInterval(intervalId);
			resolve(result);
		};
		const checkCallback = () => {
			if (promiseStatus === 'pending') return;
			try {
				const result = callback();
				if (result !== null && typeof result === 'object' && typeof (result as Promise<T>).then === 'function') {
					promiseStatus = 'pending';
					(result as Promise<T>).then((value) => {
						promiseStatus = 'resolved';
						onResolve(value);
					}, (error) => {
						promiseStatus = 'rejected';
						lastError = error;
					});
				} else {
					onResolve(result as T);
					return true;
				}
			} catch (error) {
				lastError = error;
			}
		};
		if (checkCallback() === true) return;
		timeoutId = setTimeout(() => {
			clearInterval(intervalId);
			reject(lastError ?? new Error('Timed out in waitFor!'));
		}, timeout);
		intervalId = setInterval(checkCallback, interval);
	});
}

/** `vi.waitUntil` : attend que `callback` rende une valeur vraie. */
function waitUntil<T>(callback: () => T | Promise<T>, options: number | { timeout?: number; interval?: number } = {}): Promise<T> {
	const { interval = 50, timeout = 1000 } = typeof options === 'number' ? { timeout: options } : options;
	return new Promise((resolve, reject) => {
		let promiseStatus = 'idle';
		let timeoutId: ReturnType<typeof setTimeout> | undefined;
		let intervalId: ReturnType<typeof setInterval> | undefined;
		const onResolve = (result: T) => {
			if (!result) return;
			clearTimeout(timeoutId);
			clearInterval(intervalId);
			resolve(result);
			return true;
		};
		const checkCallback = () => {
			if (promiseStatus === 'pending') return;
			try {
				const result = callback();
				if (result !== null && typeof result === 'object' && typeof (result as Promise<T>).then === 'function') {
					promiseStatus = 'pending';
					(result as Promise<T>).then((value) => {
						promiseStatus = 'resolved';
						onResolve(value);
					}, (error) => {
						clearInterval(intervalId);
						reject(error);
					});
				} else {
					return onResolve(result as T);
				}
			} catch (error) {
				clearInterval(intervalId);
				reject(error);
			}
		};
		if (checkCallback() === true) return;
		timeoutId = setTimeout(() => {
			clearInterval(intervalId);
			reject(new Error('Timed out in waitUntil!'));
		}, timeout);
		intervalId = setInterval(checkCallback, interval);
	});
}

function containsOnly(suite: SuiteNode): boolean {
	return suite.children.some((child) => child.mode === 'only' || (child.type === 'suite' && containsOnly(child)));
}

function hasRunnableTest(suite: SuiteNode, hasOnly: boolean, inOnly: boolean): boolean {
	return suite.children.some((child) => {
		if (child.mode === 'skip' || child.mode === 'todo') return false;
		const selected = !hasOnly || inOnly || child.mode === 'only';
		return child.type === 'test' ? selected : hasRunnableTest(child, hasOnly, inOnly || child.mode === 'only');
	});
}

async function withTimeout(value: unknown, timeout: number, what: string): Promise<unknown> {
	if (!(value instanceof Promise)) return value;
	let timer: ReturnType<typeof setTimeout> | undefined;
	try {
		return await Promise.race([
			value,
			new Promise((_, reject) => {
				timer = setTimeout(() => reject(new Error(`${what === 'test' ? 'Test' : `Hook ${what}`} timed out in ${timeout}ms.`)), timeout);
			}),
		]);
	} finally {
		clearTimeout(timer);
	}
}

function isAssertion(error: unknown): boolean {
	return error instanceof Error && error.name === 'AssertionError';
}

/**
 * Message d'échec comme le rapport de Vitest, qui part de la pile d'appels : « AssertionError: expected … »,
 * « TypeError: … » (pour `rejects`, Vitest reprend une pile créée en amont, qui commence par « Error: »).
 * Sans les codes couleur, que l'aperçu n'affiche pas.
 */
function messageOf(error: unknown): string {
	if (!(error instanceof Error)) return String(error);
	const header = error.stack?.match(/^([\w$]*Error): /)?.[1] ?? (error.name || 'Error');
	return `${header}: ${error.message}`.replace(/\x1b\[[0-9;]*m/g, '');
}

/** Titres de `it.each` : %s %d %i %f %j %o %%, et `$propriété` pour une ligne objet. */
function formatTitle(title: string, args: unknown[], index: number): string {
	let position = 0;
	let result = title.replace(/%[sdifjo%#]/g, (token) => {
		if (token === '%%') return '%';
		if (token === '%#') return String(index);
		const value = args[position++];
		switch (token) {
			case '%d': case '%i': return String(Number.parseInt(String(value), 10));
			case '%f': return String(Number(value));
			case '%j': case '%o': return JSON.stringify(value);
			default: return typeof value === 'string' ? value : JSON.stringify(value) ?? String(value);
		}
	});
	const [row] = args;
	if (row && typeof row === 'object' && !Array.isArray(row)) {
		result = result.replace(/\$([\w.]+)/g, (match, key: string) => {
			const value = key.split('.').reduce<any>((object, part) => object?.[part], row);
			return value === undefined ? match : typeof value === 'string' ? value : JSON.stringify(value);
		});
	}
	return result;
}
