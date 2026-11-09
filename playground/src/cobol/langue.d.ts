export const EN: boolean;
export function t(fr: string, en: string): string;
export function traduireModele<T>(modele: T, textes: Record<string, string>, codes: Record<string, string>): T;
export function cle(nom: string): string;
