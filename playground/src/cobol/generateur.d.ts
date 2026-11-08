// Types du générateur (generateur.js, écrit en JavaScript) : ce dont les tests ont besoin.
export interface Rubrique { code: string; libelle?: string; type: 'X' | '9'; longueur: number; decimales?: number; signe?: boolean }
export interface Table { code: string; nom: string; rubriques: string[]; cle: string[] }
export interface Appel {
  code: string;
  utilisation: 'C' | 'P' | 'M' | 'R' | 'I';
  structure?: string;
  fichier: string;
  argument?: string[];
  rupture?: number;
  synchro?: number;
  mouvement?: { rubrique?: string; creation: string; modification: string; annulation: string };
  donnees?: Array<Record<string, string>>;
}
/** État (chapitre 4) : libellés (-L), structures (-CE), composition (-D) ; code = fichier d'impression + lettre */
export interface Etat {
  code: string; nom?: string; longueur?: number; lignesPage?: number; postes?: number;
  totalEntiers?: number; totalDecimales?: number; condition?: string;
  libelles?: Array<{ numero: string; texte: string }>;
  structures?: Array<{ st: string; rubrique?: string; suite?: string; debut?: number; mode?: string; w?: string; source?: string; indice?: string; condition?: string; format?: string; z?: string }>;
  categories?: Array<{ ct: string; nlg?: string; type?: string; car?: string; st?: string; lb?: string; saut?: string; sautType?: string; fosf?: string; condition?: string }>;
}
export interface Modele {
  /** version des noms de zones (3 : ceux du manuel IBM) ; un modèle plus ancien est repris par normaliser */
  version?: number;
  dictionnaire: Rubrique[];
  structures: Array<{ code: string; libelle?: string; rubriques: string[] }>;
  appels?: Appel[];
  traitement: Record<string, any>;
  sorties?: Array<{ code: string; fichier: string; rubriques: string[] }>;
  tables?: Table[];
  lignes?: Array<Record<string, string>>;
  donnees?: Array<Record<string, string>>;
  etats?: Etat[];
  /** le défi relevé (defis.js), qui voyage avec le programme partagé */
  defi?: string;
}
export const exemple: Modele;
export const exempleMiseAJour: Modele;
export const exempleEtat: Modele;
export const VERSION_MODELE: number;
export function normaliser(modele: Modele): Modele & { appels: Appel[] };
export function generer(modele: Modele): string;
export function fichierDonnees(modele: Modele): string;
export function fichiersEntree(modele: Modele): Record<string, string>;
export function fichiersSortie(modele: Modele): Array<{ code: string; fichier: string }>;
export function zonesTraitees(modele: Modele): string[];
export function fonctionsGenerees(modele: Modele): string[];
export function placeFonction(fo: string, modele: Modele): string;
export function comparerCodes(a: string, b: string): number;
export function ddlTable(modele: Modele, table: Table): string;
export function verifierTables(modele: Modele, tables?: Table[]): void;
export const OPERATEURS_STRUCTURES: string[];
export const ACCES_SQL: Record<string, string>;
