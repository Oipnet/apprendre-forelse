import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiProgressStore, type ProgressStore } from '../src/app/progress.ts';
import { ExerciseSession } from '../src/app/session.ts';

function doublures() {
	const ecrits: [string, string][] = [];
	const brouillons: [Record<string, string>, number][] = [];
	const runtime = { writeFiles: vi.fn(async (files: Record<string, string>) => void ecrits.push(...Object.entries(files))) };
	const progress: ProgressStore = {
		load: async () => null,
		saveDraft: vi.fn(async (files: Record<string, string>, hints: number) => void brouillons.push([{ ...files }, hints])),
		complete: vi.fn(async () => ({ xpEarned: 30, totalXp: 130, alreadyCompleted: false })),
		revealSolution: vi.fn(async () => ({ 'src/Menu.php': '<?php // solution' })),
	};
	return { ecrits, brouillons, runtime, progress };
}

const etat = () => ({ files: { 'src/Menu.php': '<?php' }, hintsUsed: 0, solutionRevealed: false, completed: false });

describe('ExerciseSession', () => {
	beforeEach(() => vi.useFakeTimers());
	afterEach(() => vi.useRealTimers());

	it('écrit les frappes de chaque fichier après un délai, puis prévient', async () => {
		const { ecrits, runtime, progress } = doublures();
		const onWritten = vi.fn();
		const session = new ExerciseSession(runtime, progress, etat(), { onWritten });
		session.edit('src/Menu.php', 'a');
		session.edit('src/Plat.php', 'b');
		session.edit('src/Menu.php', 'c');
		expect(session.current).toEqual({ 'src/Menu.php': 'c', 'src/Plat.php': 'b' });
		expect(ecrits).toEqual([]);
		await vi.advanceTimersByTimeAsync(400);
		// Un fichier modifié juste après un autre n'efface pas sa modification.
		expect(ecrits).toEqual([['src/Menu.php', 'c'], ['src/Plat.php', 'b']]);
		expect(onWritten).toHaveBeenCalledOnce();
	});

	it('saveNow enregistre le brouillon sans attendre, et une seule fois', async () => {
		const { brouillons, runtime, progress } = doublures();
		const session = new ExerciseSession(runtime, progress, etat());
		session.edit('src/Menu.php', 'a');
		await session.saveNow();
		expect(brouillons).toEqual([[{ 'src/Menu.php': 'a' }, 0]]);
		await vi.advanceTimersByTimeAsync(2000);
		expect(brouillons).toHaveLength(1);
	});

	it('flush écrit tout de suite, et dit s\'il y avait quelque chose à écrire', async () => {
		const { ecrits, runtime, progress } = doublures();
		const onWritten = vi.fn();
		const session = new ExerciseSession(runtime, progress, etat(), { onWritten });
		session.edit('src/Menu.php', 'x');
		expect(await session.flush()).toBe(true);
		expect(await session.flush()).toBe(false);
		await vi.advanceTimersByTimeAsync(1000);
		expect(ecrits).toEqual([['src/Menu.php', 'x']]);
		expect(onWritten).not.toHaveBeenCalled();
	});

	it('enregistre le brouillon une fois, après son délai, avec les indices', async () => {
		const { brouillons, runtime, progress } = doublures();
		const session = new ExerciseSession(runtime, progress, etat());
		session.edit('src/Menu.php', 'v1');
		session.useHint();
		session.edit('src/Menu.php', 'v2');
		await vi.advanceTimersByTimeAsync(1499);
		expect(brouillons).toEqual([]);
		await vi.advanceTimersByTimeAsync(1);
		expect(brouillons).toEqual([[{ 'src/Menu.php': 'v2' }, 1]]);
	});

	it('à la réussite, enregistre d\'abord le code qui a réussi, sans attendre le brouillon', async () => {
		const { brouillons, runtime, progress } = doublures();
		const session = new ExerciseSession(runtime, progress, etat());
		session.edit('src/Menu.php', 'réussi');
		const result = await session.complete();
		expect(brouillons).toEqual([[{ 'src/Menu.php': 'réussi' }, 0]]);
		expect(result.xpEarned).toBe(30);
		expect(session.completed).toBe(true);
		await vi.advanceTimersByTimeAsync(2000);
		expect(brouillons).toHaveLength(1);
	});

	it('un indice coûte de l\'XP, sauf une fois réussi ou la solution consultée', async () => {
		const { runtime, progress } = doublures();
		const session = new ExerciseSession(runtime, progress, etat());
		expect(session.hintCost(100)).toBe(25);
		session.useHint();
		session.useHint();
		session.useHint();
		expect(session.hintCost(100)).toBe(0); // plancher à 25 %
		const autre = new ExerciseSession(runtime, progress, etat());
		expect(await autre.revealSolution()).toEqual({ 'src/Menu.php': '<?php // solution' });
		expect(autre.solutionRevealed).toBe(true);
		expect(autre.hintCost(100)).toBe(0);
	});

	it('la solution refusée (invité) ne compte pas comme consultée', async () => {
		const { runtime, progress } = doublures();
		progress.revealSolution = async () => null;
		const session = new ExerciseSession(runtime, progress, etat());
		expect(await session.revealSolution()).toBeNull();
		expect(session.solutionRevealed).toBe(false);
	});

	/** Une promesse qu'on tient à la main : l'écriture « en vol » du runtime. */
	function enVol() {
		let tenir!: () => void;
		let rompre!: (e: Error) => void;
		const promesse = new Promise<void>((resolve, reject) => {
			tenir = resolve;
			rompre = reject;
		});
		return { promesse, tenir, rompre };
	}

	it('un flush pendant une écriture en cours l\'attend, puis écrit ce qui reste : les tests voient le dernier code', async () => {
		const { ecrits, progress } = doublures();
		const premier = enVol();
		const lots: Record<string, string>[] = [];
		const runtime = {
			writeFiles: vi.fn(async (files: Record<string, string>) => {
				lots.push(files);
				if (lots.length === 1) await premier.promesse;
				ecrits.push(...Object.entries(files));
			}),
		};
		const session = new ExerciseSession(runtime, progress, etat());
		session.edit('src/A.php', 'a');
		const minuteur = session.flush(); // le minuteur envoie A, lent à répondre
		session.edit('src/B.php', 'b');
		let fini = false;
		const lancerLesTests = session.flush().then((ecrit) => {
			fini = true;
			return ecrit;
		});

		await vi.advanceTimersByTimeAsync(0);
		expect(fini, 'Le second flush attend le premier.').toBe(false);
		premier.tenir();
		expect(await lancerLesTests).toBe(true);
		await minuteur;
		expect(lots).toEqual([{ 'src/A.php': 'a' }, { 'src/B.php': 'b' }]);
		expect(ecrits).toEqual([['src/A.php', 'a'], ['src/B.php', 'b']]);
	});

	it('une écriture refusée garde ses fichiers en attente : le flush suivant les renvoie', async () => {
		const { progress } = doublures();
		const lots: Record<string, string>[] = [];
		let refuser = true;
		const runtime = {
			writeFiles: vi.fn(async (files: Record<string, string>) => {
				lots.push(files);
				if (refuser) throw new Error('worker redémarré');
			}),
		};
		const session = new ExerciseSession(runtime, progress, etat());
		session.edit('src/A.php', 'a');
		session.edit('src/B.php', 'b');

		await expect(session.flush()).rejects.toThrow('worker redémarré');
		session.edit('src/A.php', 'a2'); // modifié depuis : la version récente l'emporte
		refuser = false;
		expect(await session.flush()).toBe(true);
		expect(lots[1]).toEqual({ 'src/A.php': 'a2', 'src/B.php': 'b' });
	});

	it('un 401 à l\'enregistrement du brouillon est dit à l\'apprenant', async () => {
		const { runtime } = doublures();
		vi.stubGlobal('fetch', vi.fn(async () => new Response(null, { status: 401 })));
		const erreurs: string[] = [];
		const session = new ExerciseSession(runtime, new ApiProgressStore('/api/progress/t/e'), etat(), { onDraftError: (e) => erreurs.push(e.message) });

		session.edit('src/Menu.php', 'x');
		await vi.advanceTimersByTimeAsync(1500);

		expect(erreurs).toHaveLength(1);
		expect(erreurs[0]).toContain('votre session a expiré');
		vi.unstubAllGlobals();
	});

	it('à la fermeture, le brouillon en attente part tout de suite (keepalive), et rien s\'il n\'y en a pas', async () => {
		const { runtime } = doublures();
		const fetch = vi.fn(async (_url: string, _init?: RequestInit) => new Response(null, { status: 204 }));
		vi.stubGlobal('fetch', fetch);
		const session = new ExerciseSession(runtime, new ApiProgressStore('/api/progress/t/e'), etat());

		session.saveOnExit();
		expect(fetch).not.toHaveBeenCalled();
		session.edit('src/Menu.php', 'dernières frappes');
		session.saveOnExit();

		expect(fetch).toHaveBeenCalledOnce();
		const [, init] = fetch.mock.calls[0];
		expect(init?.keepalive).toBe(true);
		expect(JSON.parse(String(init?.body)).files['src/Menu.php']).toBe('dernières frappes');
		await vi.advanceTimersByTimeAsync(2000);
		expect(fetch, 'Le brouillon différé est annulé : il est déjà parti.').toHaveBeenCalledOnce();
		vi.unstubAllGlobals();
	});
});
