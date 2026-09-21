/**
 * Lance les tests d'un projet (fichiers *.test.ts / *.spec.ts) contre le simulateur, et note au besoin
 * les tests de l'apprenant par mutants, avec les mêmes règles que le runtime PHP (php-worker.ts) et
 * que content:check (ExerciseChecker).
 */
import { ModuleLoader, type ProjectFiles } from '../compiler.ts';
import { createE2E, type SimulatedServer } from './e2e.ts';
import { resolveEnvironments } from './environment.ts';
import { loadNuxtTestFile, type NuxtTestEnvironment } from './nuxt/environment.ts';
import type { Grading, TestCaseResult, TestRunResult } from './types.ts';
import { TestFile } from './vitest.ts';

const TEST_FILE_RE = /(^|\/)[^/]+\.(test|spec)\.[cm]?[jt]sx?$/;

export interface RunOptions {
	grading?: Grading;
	/** Serveur neuf pour un fichier de test, sur ces fichiers de projet (un par fichier, comme setup()). */
	createServer: (files: ProjectFiles) => SimulatedServer;
	/** Fichiers de test à lancer (tous par défaut). */
	only?: string[];
	/** Environnement `nuxt` (mountSuspended…) : runtime de test et happy-dom, fournis par l'hôte. */
	nuxt?: NuxtTestEnvironment;
}

export function testFiles(files: ProjectFiles): string[] {
	return [...files.keys()].filter((path) => TEST_FILE_RE.test(path) && !path.split('/').includes('node_modules')).sort();
}

export async function runTests(files: ProjectFiles, options: RunOptions): Promise<TestRunResult> {
	const started = performance.now();
	const result = await runFiles(files, options, options.only ?? testFiles(files));
	if (options.grading?.ownTests.length) {
		await gradeOwnTests(files, options, result);
	}
	return { ...result, output: report(result), durationMs: performance.now() - started };
}

interface RawRun {
	exitCode: number;
	cases: TestCaseResult[];
	/** Fichiers qui n'ont pas pu être chargés, avec leur erreur. */
	fileErrors: { file: string; message: string }[];
	output: string;
	durationMs: number;
}

async function runFiles(files: ProjectFiles, options: RunOptions, paths: string[]): Promise<RawRun> {
	const cases: TestCaseResult[] = [];
	const fileErrors: RawRun['fileErrors'] = [];
	let environments: Map<string, string>;
	try {
		environments = await resolveEnvironments(files, paths);
	} catch (error) {
		const message = `vitest.config : ${error instanceof Error ? `${error.name}: ${error.message}` : String(error)}`;
		return { exitCode: 1, cases, fileErrors: paths.map((file) => ({ file, message })), output: '', durationMs: 0 };
	}
	for (const path of paths) {
		const testFile = new TestFile(path);
		if (environments.get(path) === 'nuxt') {
			if (!options.nuxt) {
				fileErrors.push({ file: path, message: 'Error: l\'environnement de test « nuxt » n\'est pas disponible ici.' });
				continue;
			}
			let loaded;
			try {
				loaded = await loadNuxtTestFile(files, path, testFile, options.nuxt);
			} catch (error) {
				fileErrors.push({ file: path, message: error instanceof Error ? `${error.name}: ${error.message}` : String(error) });
				continue;
			}
			cases.push(...(await loaded.run()));
			continue;
		}
		const server = options.createServer(files);
		const e2e = createE2E(server);
		const loader = new ModuleLoader(files, {}, {
			packages: { vitest: testFile.api(), '@nuxt/test-utils/e2e': e2e, '@nuxt/test-utils': e2e, 'bac-a-sable': server.sandbox?.() ?? {} },
		});
		try {
			await loader.loadWithTopLevelAwait(path);
			await testFile.collected();
		} catch (error) {
			fileErrors.push({ file: path, message: error instanceof Error ? `${error.name}: ${error.message}` : String(error) });
			continue;
		}
		try {
			cases.push(...(await testFile.run()));
		} finally {
			testFile.unstubAllGlobals();
		}
	}
	const failed = fileErrors.length > 0 || cases.some((c) => c.status === 'failed' || c.status === 'error' || (c.status === 'skipped' && c.message));
	return { exitCode: failed || cases.length === 0 ? 1 : 0, cases, fileErrors, output: '', durationMs: 0 };
}

async function gradeOwnTests(files: ProjectFiles, options: RunOptions, result: RawRun): Promise<void> {
	const grading = options.grading!;
	const synthetic = (name: string, passed: boolean, message?: string): TestCaseResult => ({ className: 'Notation', name, status: passed ? 'passed' : 'failed', message, timeMs: 0 });
	const own = result.cases.filter((c) => c.file && grading.ownTests.includes(c.file));
	const failing = own.filter((c) => c.status !== 'passed');
	const ownPassing = own.length > 0 && failing.length === 0;
	result.cases.push(synthetic(
		'own-tests',
		ownPassing,
		own.length === 0
			? 'Aucun de vos tests ne s\'est exécuté : écrivez au moins un it(…) dans votre fichier de test.'
			: `${failing.length === 1 ? 'Un de vos tests ne passe' : `${failing.length} de vos tests ne passent`} pas sur l'application correcte : ${failing.map((c) => (c.status === 'skipped' ? `${c.name} (ignoré)` : c.name)).join(', ')}.`,
	));
	for (const mutant of grading.mutants) {
		if (!ownPassing) {
			result.cases.push(synthetic(`mutant:${mutant.id}`, false, 'Vos tests doivent d\'abord tous passer sur l\'application correcte.'));
			continue;
		}
		const mutated = new Map(files);
		for (const change of mutant.changes) {
			mutated.set(change.file, (mutated.get(change.file) ?? '').split(change.search).join(change.replace));
		}
		const run = await runFiles(mutated, options, grading.ownTests.filter((path) => files.has(path)));
		// Détecté si un de vos tests échoue (ou si le fichier ne se charge plus : l'application est cassée).
		const detected = run.fileErrors.length > 0 || run.cases.length === 0 || run.cases.some((c) => c.status !== 'passed');
		result.cases.push(synthetic(`mutant:${mutant.id}`, detected, `Vos tests passent encore quand ${mutant.label} : il manque un test.`));
	}
}

/** Sortie à la manière du rapporteur par défaut de Vitest. */
function report(run: RawRun): string {
	const lines: string[] = [];
	for (const error of run.fileErrors) {
		lines.push(` FAIL  ${error.file}`, `   ${error.message}`);
	}
	for (const c of run.cases) {
		const title = [c.file, c.className, c.name].filter(Boolean).join(' > ');
		const mark = { passed: '✓', failed: '×', error: '×', skipped: '↓' }[c.status];
		lines.push(` ${mark} ${title}${c.status === 'skipped' ? '' : ` ${c.timeMs}ms`}`);
		if (c.message && c.status !== 'passed') lines.push(`   → ${c.message}`);
	}
	const count = (status: TestCaseResult['status'][]) => run.cases.filter((c) => status.includes(c.status)).length;
	const files = new Set([...run.cases.map((c) => c.file), ...run.fileErrors.map((e) => e.file)]).size;
	const parts = [count(['failed', 'error']) && `${count(['failed', 'error'])} failed`, count(['passed']) && `${count(['passed'])} passed`, count(['skipped']) && `${count(['skipped'])} skipped`].filter(Boolean);
	lines.push('', ` Test Files  ${files}`, `      Tests  ${parts.join(' | ') || 'aucun test'} (${run.cases.length})`);
	return lines.join('\n');
}
