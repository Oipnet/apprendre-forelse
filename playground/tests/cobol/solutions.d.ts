import type { Modele } from '../../src/cobol/generateur.js';

export const SOLUTIONS: Record<string, (m: Modele & { appels: import('../../src/cobol/generateur.js').Appel[] }) => Modele>;
