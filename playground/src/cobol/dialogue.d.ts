// Types des écrans de saisie (dialogue.js, écrit en JavaScript) : ce dont les tests ont besoin.
import type { Ecran, Modele } from './generateur.js';
export interface Analyse {
  code: string; scr: string; nom: string; irr: number; rh: number;
  champs: Array<Record<string, any>>; elements: Array<Record<string, any>>; segments: Array<Record<string, any>>;
  touches: Array<{ touche: string; oper?: string; ecran?: string; simulation?: boolean }>;
}
export interface Sortie { oper: string; suite: string; curseur: string | null; lierr: string; valeurs: Record<string, string>; erreurs: Record<string, string> }
export const ANNULATION: string;
export const OPERATIONS: Record<string, string>;
export function ecranVide(code: string, nom?: string): Ecran;
export function normaliserEcran(e: Ecran): Ecran;
export function analyserEcran(modele: Modele, ecran: Ecran): Analyse;
export function zonesMaquette(m: Analyse, options?: { valeurs?: Record<string, string>; erreurs?: Record<string, string>; simulation?: boolean }): Array<Record<string, any>>;
export function messageEntree(m: Analyse, options?: { icf?: string; touche?: string; valeurs?: Record<string, string> }): string;
export function lireSortie(m: Analyse, texte: string): Sortie;
export function genererEcran(modele: Modele, ecran: Ecran): string;
