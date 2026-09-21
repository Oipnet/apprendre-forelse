/**
 * Conformité du runner de tests : les fichiers *.exemple.ts de conformite/runner/ sont lancés par le vrai Vitest
 * (rapport JSON) et par celui du simulateur. Pour chaque test : même statut, même première ligne de
 * message d'échec.
 */
import { spawnSync } from 'node:child_process';
import { mkdtempSync, readdirSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { beforeAll, describe, expect, it } from 'vitest';
import { runTests } from '../src/testing/run.ts';

const RUNNER = join(import.meta.dirname, 'runner');
const FILES = readdirSync(RUNNER).filter((file) => file.endsWith('.exemple.ts')).sort();

type Verdicts = Record<string, { status: string; message: string }>;

const reel: Verdicts = {};
const simule: Verdicts = {};

beforeAll(async () => {
	const output = join(mkdtempSync(join(tmpdir(), 'vitest-conformite-')), 'rapport.json');
	// Le runner du simulateur joue le rôle d'un Vitest lancé par le développeur : ni l'environnement de
	// Vitest, ni le mode CI (détecté par CI, GITHUB_ACTIONS…), où Vitest refuse `.only` : --allowOnly.
	const env = Object.fromEntries(Object.entries(process.env).filter(([name]) => !/^(NODE_ENV|TEST|VITEST.*|CI)$/.test(name)));
	spawnSync(join(import.meta.dirname, '../node_modules/.bin/vitest'), ['run', '--root', RUNNER, '--allowOnly', '--reporter=json', `--outputFile=${output}`], { env });
	const report = JSON.parse(readFileSync(output, 'utf8')) as { testResults: { name: string; assertionResults: { ancestorTitles: string[]; title: string; status: string; failureMessages: string[] }[] }[] };
	for (const file of report.testResults) {
		for (const test of file.assertionResults) {
			const key = [file.name.split('/').pop(), ...test.ancestorTitles, test.title].join(' > ');
			reel[key] = { status: ['pending', 'todo'].includes(test.status) ? 'skipped' : test.status, message: test.failureMessages[0]?.split('\n')[0].replace(/\x1b\[[0-9;]*m/g, '') ?? '' };
		}
	}

	const files = new Map(FILES.map((file) => [file, readFileSync(join(RUNNER, file), 'utf8')]));
	const result = await runTests(files, { only: FILES, createServer: () => ({ request: () => Promise.reject(new Error('pas de serveur')) }) });
	for (const test of result.cases) {
		const key = [test.file, test.className, test.name].filter(Boolean).join(' > ');
		simule[key] = { status: test.status === 'error' ? 'failed' : test.status, message: test.status === 'skipped' ? '' : test.message?.split('\n')[0] ?? '' };
	}
}, 120_000);

describe('conformité du runner avec Vitest', () => {
	it('trouve les mêmes tests', () => {
		expect(Object.keys(simule).sort()).toEqual(Object.keys(reel).sort());
	});

	for (const file of FILES) {
		it(`même verdict pour chaque test de ${file}`, () => {
			const pick = (verdicts: Verdicts) => Object.fromEntries(Object.entries(verdicts).filter(([key]) => key.startsWith(`${file} >`)));
			expect(pick(simule)).toEqual(pick(reel));
		});
	}
});
