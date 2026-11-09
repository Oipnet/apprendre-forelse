// Types du partage par lien (partage.js) : ce dont les tests ont besoin.
import type { Modele } from './generateur.js';

export function fragmentDe(modele: Modele): Promise<string>;
export function lireFragment(hash?: string): Promise<(Modele & { appels: import('./generateur.js').Appel[] }) | null>;
