/**
 * Conformité de l'environnement de test `nuxt` (@nuxt/test-utils) : les fichiers de conformite/reference/test/nuxt/
 * sont lancés par le vrai Vitest avec le vrai @nuxt/test-utils (environnement `nuxt`, happy-dom), puis par
 * le simulateur. Pour chaque test : même statut, et même première ligne de message d'échec.
 */
import { spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { beforeAll, describe, expect, it } from 'vitest';
import { NuxtSimulator } from '../src/index.ts';
import { loadProject, nodeTestEnvironment } from '../src/node/index.ts';

const REFERENCE = join(import.meta.dirname, 'reference');
const FILES = ['test/nuxt/echecs.test.ts', 'test/nuxt/endpoints.test.ts', 'test/nuxt/montage.test.ts', 'test/nuxt/simulations.test.ts'];

type Verdicts = Record<string, { status: string; message: string }>;

const reel: Verdicts = {};
const simule: Verdicts = {};

beforeAll(async () => {
	const output = join(mkdtempSync(join(tmpdir(), 'vitest-nuxt-')), 'rapport.json');
	// Comme un Vitest lancé par le développeur : ni l'environnement de Vitest, ni le mode CI.
	const env = Object.fromEntries(Object.entries(process.env).filter(([name]) => !/^(NODE_ENV|TEST|VITEST.*|CI)$/.test(name)));
	// Le Vitest du projet de référence : c'est lui qui a le vrai @nuxt/test-utils et Nuxt à côté.
	const vitest = spawnSync(join(REFERENCE, 'node_modules/.bin/vitest'), ['run', '--project', 'nuxt', '--reporter=json', `--outputFile=${output}`], { cwd: REFERENCE, env });
	const report = JSON.parse(readFileSync(output, 'utf8')) as { testResults: { name: string; assertionResults: { ancestorTitles: string[]; title: string; status: string; failureMessages: string[] }[] }[] };
	if (!report.testResults?.length) throw new Error(`Vitest n'a rien rapporté : ${vitest.stderr}`);
	for (const file of report.testResults) {
		for (const test of file.assertionResults) {
			reel[key(file.name.slice(REFERENCE.length + 1), test.ancestorTitles, test.title)] = {
				status: ['pending', 'todo'].includes(test.status) ? 'skipped' : test.status,
				message: firstLine(test.failureMessages[0]),
			};
		}
	}

	const simulator = new NuxtSimulator(loadProject(REFERENCE), { testEnvironment: nodeTestEnvironment() });
	const result = await simulator.runTests(undefined, FILES);
	for (const test of result.cases) {
		simule[key(test.file ?? '', test.className ? test.className.split(' > ') : [], test.name)] = {
			status: test.status === 'error' ? 'failed' : test.status,
			message: test.status === 'skipped' ? '' : firstLine(test.message),
		};
	}
}, 300_000);

function key(file: string, suites: string[], title: string): string {
	return [file, ...suites, title].filter(Boolean).join(' > ');
}

function firstLine(message: string | undefined): string {
	return message?.split('\n')[0].replace(/\x1b\[[0-9;]*m/g, '') ?? '';
}

describe('conformité de l’environnement de test nuxt', () => {
	it('trouve les mêmes tests', () => {
		expect(Object.keys(simule).sort()).toEqual(Object.keys(reel).sort());
	});

	for (const file of FILES) {
		it(`même verdict pour chaque test de ${file}`, () => {
			const pick = (verdicts: Verdicts) => Object.fromEntries(Object.entries(verdicts).filter(([name]) => name.startsWith(`${file} >`)));
			expect(pick(simule)).toEqual(pick(reel));
		});
	}
});
