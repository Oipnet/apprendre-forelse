/**
 * Les textes du playground, dans la langue de la page : `import { m } from '../i18n'`, puis `m.boot.firstLoad`.
 *
 * Le français (la référence) et l'anglais existent. Une langue de plus s'écrit dans son fichier
 * (`export const en: Messages`, voir en.ts), que tsc vérifie complet face à `fr`, puis s'ajoute à CATALOGUES.
 */
import { en } from './en.ts';
import { fr } from './fr.ts';

/** La forme d'un catalogue : celle de `fr`, chaque texte élargi en `string`. */
type Elargi<T> = T extends string ? string : T extends (...args: infer A) => infer R ? (...args: A) => Elargi<R> : { readonly [K in keyof T]: Elargi<T[K]> };
export type Messages = Elargi<typeof fr>;

const CATALOGUES: Record<string, Messages> = { fr, en };

/** Le catalogue d'une langue (« fr », « fr-FR »…) ; le français pour une langue absente ou inconnue. */
export function catalogue(lang: string | null | undefined): Messages {
	const langue = (lang ?? '').trim().toLowerCase().split('-')[0];
	return Object.hasOwn(CATALOGUES, langue) ? CATALOGUES[langue] : fr;
}

/** La langue de la page ; aucune hors d'une page (worker du runtime, tests sans DOM). */
function langueDeLaPage(): string | null {
	try {
		return typeof document === 'undefined' ? null : document.documentElement?.lang ?? null;
	} catch {
		return null;
	}
}

export const m: Messages = catalogue(langueDeLaPage());
