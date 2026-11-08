// Types du générateur (generateur.js, écrit en JavaScript) : ce dont les tests ont besoin.
export interface Rubrique { code: string; libelle?: string; type: 'X' | '9'; longueur: number; decimales?: number; signe?: boolean }
export interface Table { code: string; nom: string; rubriques: string[]; cle: string[] }
export interface Modele {
  dictionnaire: Rubrique[];
  structures: Array<{ code: string; libelle?: string; rubriques: string[] }>;
  traitement: Record<string, any>;
  sorties?: Array<{ code: string; fichier: string; rubriques: string[] }>;
  tables?: Table[];
  lignes?: Array<Record<string, string>>;
  donnees: Array<Record<string, string>>;
}
export const exemple: Modele;
export function generer(modele: Modele): string;
export function fichierDonnees(modele: Modele): string;
export function ddlTable(modele: Modele, table: Table): string;
export function verifierTables(modele: Modele, tables?: Table[]): void;
export const OPERATEURS_STRUCTURES: string[];
export const ACCES_SQL: Record<string, string>;
