/**
 * Lance les tests Vitest d'un projet Nuxt avec le simulateur, pour content:check (ExerciseChecker) :
 * même runner et même verdict que dans le navigateur. Sortie : JSON sur la sortie standard.
 *
 *     node --experimental-transform-types bin/tests.ts <dossier du projet> [fichiers de test…]
 */
import { join } from 'node:path';
import { NuxtSimulator } from '../src/index.ts';
import { loadProject, nodeTestEnvironment } from '../src/node/index.ts';

const [directory, ...only] = process.argv.slice(2);
if (!directory) {
	process.stderr.write('usage : bin/tests.ts <dossier du projet> [fichiers de test…]\n');
	process.exit(2);
}

const simulator = new NuxtSimulator(loadProject(directory), { testEnvironment: nodeTestEnvironment() });
const result = await simulator.runTests(undefined, only.length > 0 ? only : undefined);
process.stdout.write(`${JSON.stringify({
	exitCode: result.exitCode,
	output: result.output,
	cases: result.cases.map((c) => ({ ...c, file: c.file ? join(directory, c.file) : undefined })),
})}\n`);
