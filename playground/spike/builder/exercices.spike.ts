/**
 * Essai php-wasm-builder : les exercices de Pratique, sur les deux runtimes. Pour chacun, le code de départ
 * avec les tests de l'exercice, puis la solution par-dessus : la solution doit tout réussir, et les deux
 * runtimes doivent donner le même verdict.
 *
 * CONTENU : la racine du dépôt de contenu. Les tableaux vont dans $GITHUB_STEP_SUMMARY quand il existe.
 */
import { appendFileSync, existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { afterAll, describe, expect, it } from 'vitest';
import { ENVS, laravel, projet, type Runtime, spec, symfony } from './commun.ts';

const PRATIQUE = join(process.env.CONTENU ?? '', 'pratique/practice');

/** Les fichiers d'un dossier, par chemin relatif à `racine`, sous le préfixe donné. */
function fichiers(dossier: string, prefixe = ''): Record<string, string> {
	const resultat: Record<string, string> = {};
	if (!existsSync(dossier)) return resultat;
	const parcourir = (courant: string) => {
		for (const nom of readdirSync(courant)) {
			const chemin = join(courant, nom);
			if (statSync(chemin).isDirectory()) parcourir(chemin);
			else resultat[prefixe + relative(dossier, chemin)] = readFileSync(chemin, 'utf8');
		}
	};
	parcourir(dossier);
	return resultat;
}

const exercices = readdirSync(PRATIQUE)
	.map((id) => ({ id, environnement: readFileSync(join(PRATIQUE, id, 'exercise.yaml'), 'utf8').match(/^environment:\s*(\S+)/m)?.[1] ?? '' }))
	.filter(({ environnement }) => existsSync(join(ENVS, `${environnement}.zip`)));

const lignes: string[] = [];
const verdicts = new Map<string, string>();
afterAll(() => {
	const tableau = ['| Runtime | Exercice | Départ | Solution | Durée |', '|---|---|---|---|---|', ...lignes].join('\n');
	const ecarts = [...new Set([...verdicts.keys()].map((cle) => cle.split(' ')[1]))]
		.filter((id) => verdicts.get(`php-wasm ${id}`) !== verdicts.get(`builder ${id}`));
	const resume = `${exercices.length} exercices ; verdicts différents entre les runtimes : ${ecarts.length ? ecarts.join(', ') : 'aucun'}`;
	console.log(`${resume}\n${tableau}`);
	if (process.env.GITHUB_STEP_SUMMARY) appendFileSync(process.env.GITHUB_STEP_SUMMARY, `\n### Exercices de Pratique\n\n${resume}\n\n${tableau}\n`);
});

describe.each(['php-wasm', 'builder'] as Runtime[])('%s', (runtime) => {
	it.each(exercices)('$id', async ({ id, environnement }) => {
		const debut = performance.now();
		const exercice = join(PRATIQUE, id);
		const p = projet(runtime);
		await p.boot(spec(environnement, environnement.startsWith('laravel') ? laravel : symfony));
		await p.writeFiles({ ...fichiers(join(exercice, 'starter')), ...fichiers(join(exercice, 'tests'), 'tests/') });
		const depart = await p.runTests();
		await p.writeFiles(fichiers(join(exercice, 'solution')));
		const solution = await p.runTests();
		const compte = (r: typeof depart) => `${r.cases.filter((c) => c.status === 'passed').length}/${r.cases.length}`;
		const echecs = solution.cases.filter((c) => c.status !== 'passed').map((c) => `${c.name} : ${(c.message ?? '').split('\n')[0].slice(0, 100)}`);
		verdicts.set(`${runtime} ${id}`, `${compte(depart)} ${compte(solution)}`);
		lignes.push(`| ${runtime} | ${id} | ${compte(depart)} | ${compte(solution)}${echecs.length ? ` ❌ ${echecs.join(' ; ').replace(/\|/g, '\\|')}` : ''}${solution.cases.length ? '' : ` ❌ ${solution.output.replace(/\s+/g, ' ').slice(0, 200)}`} | ${Math.round(performance.now() - debut)} ms |`);
		expect.soft(solution.cases.length).toBeGreaterThan(0);
		expect.soft(echecs).toEqual([]);
	});
});
