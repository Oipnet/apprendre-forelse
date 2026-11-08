import type { Modele } from '../../src/cobol/generateur.js';

export const cas: Array<[string, Modele]>;
export const SOLUTIONS: Record<string, (m: Modele & { appels: import('../../src/cobol/generateur.js').Appel[] }) => Modele>;
