/**
 * Essai temporaire PHP 8.6 : chaque exercice de Pratique, départ avec ses tests puis solution par-dessus.
 * La solution doit tout réussir. CONTENU : la racine du dépôt de contenu ; MOTEUR_ENVS : environments/ du moteur.
 */
import { appendFileSync, existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { afterAll, describe, expect, it } from 'vitest';
import { ENVS, projet, spec } from './commun.ts';

const PRATIQUE = join(process.env.CONTENU ?? '', 'pratique/practice');
const RACINE_ENVS = process.env.MOTEUR_ENVS ?? '';

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
let rates = 0;
afterAll(() => {
	const tableau = ['| Exercice | Environnement | Départ | Solution | Durée |', '|---|---|---|---|---|', ...lignes].join('\n');
	const resume = `${exercices.length} exercices, ${rates} solution(s) en échec`;
	console.log(`${resume}\n${tableau}`);
	if (process.env.GITHUB_STEP_SUMMARY) appendFileSync(process.env.GITHUB_STEP_SUMMARY, `\n### Exercices de Pratique en PHP 8.6\n\n${resume}\n\n${tableau}\n`);
});

describe('php-wasm 8.6', () => {
	it.each(exercices)('$id', async ({ id, environnement }) => {
		const debut = performance.now();
		const exercice = join(PRATIQUE, id);
		const p = projet();
		await p.boot(spec(environnement, RACINE_ENVS));
		await p.writeFiles({ ...fichiers(join(exercice, 'starter')), ...fichiers(join(exercice, 'tests'), 'tests/') });
		const depart = await p.runTests();
		await p.writeFiles(fichiers(join(exercice, 'solution')));
		const solution = await p.runTests();
		const compte = (r: typeof depart) => `${r.cases.filter((c) => c.status === 'passed').length}/${r.cases.length}`;
		const echecs = solution.cases.filter((c) => c.status !== 'passed').map((c) => `${c.name} : ${(c.message ?? '').split('\n')[0].slice(0, 120)}`);
		if (echecs.length || !solution.cases.length) { rates++; console.log(`--- ${id}\n${solution.output.slice(0, 3000)}`); }
		lignes.push(`| ${id} | ${environnement} | ${compte(depart)} | ${compte(solution)}${echecs.length ? ` ❌ ${echecs.join(' ; ').replace(/\|/g, '\\|')}` : ''}${solution.cases.length ? '' : ` ❌ ${solution.output.replace(/\s+/g, ' ').slice(0, 200).replace(/\|/g, '\\|')}`} | ${Math.round(performance.now() - debut)} ms |`);
		expect.soft(solution.cases.length).toBeGreaterThan(0);
		expect.soft(echecs).toEqual([]);
	}, 300_000);
});
