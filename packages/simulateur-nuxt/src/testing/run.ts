/**
 * Lance les tests d'un projet (fichiers *.test.ts / *.spec.ts) contre le simulateur, et note au besoin
 * les tests de l'apprenant par mutants, avec la notation du contrat des runtimes (gradeOwnTests), celle
 * de content:check (ExerciseChecker).
 */
import { gradeOwnTests as gradeMutants } from '@forelse/runtime-contract';
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

/** Notation par mutants du contrat : chaque mutant est joué sur une copie des fichiers du projet. */
async function gradeOwnTests(files: ProjectFiles, options: RunOptions, result: RawRun): Promise<void> {
	const grading = options.grading!;
	const { cases } = await gradeMutants(grading, result.cases, async (mutant) => {
		const mutated = new Map(files);
		for (const change of mutant.changes) {
			mutated.set(change.file, (mutated.get(change.file) ?? '').split(change.search).join(change.replace));
		}
		const run = await runFiles(mutated, options, grading.ownTests.filter((path) => files.has(path)));
		// Un fichier qui ne se charge plus : l'application est cassée, le mutant est détecté.
		return { cases: run.cases, broken: run.fileErrors.length > 0 };
	}, {
		noOwnTest: 'Aucun de vos tests ne s\'est exécuté : écrivez au moins un it(…) dans votre fichier de test.',
		skipped: '(ignoré)',
	});
	result.cases.push(...cases);
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
