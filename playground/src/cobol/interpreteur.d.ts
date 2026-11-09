// Types de l'interpréteur (interpreteur.js, écrit en JavaScript) : ce dont les tests ont besoin.
export class ErreurCobol extends Error { ligne?: number }
export interface Resultat {
  sortie: string;
  fichiers: Record<string, string>;
  instructions: number;
  sql: { executees: number; erreurs: Array<{ ligne: number; code: number; message: string }> };
}
export class Programme {
  constructor(source: string);
  executer(options?: { fichiers?: Record<string, string>; maxInstructions?: number; db?: unknown }): Resultat;
  utiliseSql(): boolean;
}
