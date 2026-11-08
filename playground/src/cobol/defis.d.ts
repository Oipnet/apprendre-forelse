// Types des défis (defis.js) : ce dont les tests ont besoin.
import type { Modele } from './generateur.js';

export interface Defi { titre: string; enonce: string[]; modele: Modele; attendu: string }
export const DEFIS: Record<string, Defi>;
export function defiDuFragment(hash?: string): string | null;
export function comparer(sortie: string | null | undefined, attendu: string): { reussi: true } | { reussi: false; ligne: number; attendue: string; obtenue: string };
