/**
 * Les textes du runtime Goravel : `catalogue(langue)`, puis `m.boot.starting`.
 *
 * Le runtime tourne dans un worker, sans la page : la langue lui arrive par l'option `locale` du démarrage
 * (posée par le playground d'après la page), et le code Go la reçoit dans FORELSE_LANG (go/learner/lang.go).
 * Une langue de plus s'écrit dans son fichier (`export const en: Messages`), que tsc vérifie complet face à
 * `fr`, puis s'ajoute à CATALOGUES.
 */
import { en } from './en.ts';
import { fr } from './fr.ts';

/** La forme d'un catalogue : celle de `fr`, chaque texte élargi en `string`. */
type Elargi<T> = T extends string ? string : T extends (...args: infer A) => infer R ? (...args: A) => Elargi<R> : { readonly [K in keyof T]: Elargi<T[K]> };
export type Messages = Elargi<typeof fr>;

const CATALOGUES: Record<string, Messages> = { fr, en };

/** La langue (« fr », « en-GB »…) ramenée à son code ; le français pour une langue absente ou inconnue. */
export function langue(lang: string | null | undefined): string {
	const code = (lang ?? '').trim().toLowerCase().split(/[-_]/)[0];
	return Object.hasOwn(CATALOGUES, code) ? code : 'fr';
}

/** Le catalogue d'une langue ; le français pour une langue absente ou inconnue. */
export function catalogue(lang: string | null | undefined): Messages {
	return CATALOGUES[langue(lang)];
}
