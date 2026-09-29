import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { ProgressStore } from '../src/app/progress.ts';
import { ExerciseSession } from '../src/app/session.ts';

function doublures() {
	const ecrits: [string, string][] = [];
	const brouillons: [Record<string, string>, number][] = [];
	const runtime = { writeFile: vi.fn(async (path: string, content: string) => void ecrits.push([path, content])) };
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
});
