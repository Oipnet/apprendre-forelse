import { mesurer } from '../mesure';
import { escapeHtml } from './html';
import type { ScratchOffer } from './types';

/**
 * L'encart du parcours sous l'aperçu du bac à sable : le visiteur qui a déjà fait tourner son code plusieurs fois
 * apprend qu'un parcours guidé l'attend dans ce même éditeur. Le bac à sable est la première porte d'entrée du site ;
 * rien n'y menait vers une offre.
 *
 * Discret : pas au premier clic, seulement après RUNS exécutions réussies (« Exécuter » suivi d'une réponse de
 * l'aperçu sans erreur). La croix le ferme pour de bon (retenu dans le navigateur, par parcours). Le texte, le prix et
 * le lien viennent du serveur (App\Scratch\ScratchOfferFactory), qui ne l'envoie que si le parcours est en vente pour
 * ce visiteur. La mesure : `bac-a-sable-offre` avec `action` = vue, clic ou fermee.
 */
export const RUNS = 2;

const ferme = (track: string) => `forelse:bac-a-sable-offre:${track}`;

export class ScratchOfferBanner {
	private reussies = 0;
	private enAttente = false;
	private affiche = false;

	constructor(
		private readonly host: HTMLElement,
		private readonly offer: ScratchOffer,
		private readonly mesure: Record<string, string>,
	) {}

	/** Le visiteur a cliqué sur « Exécuter » : la prochaine réponse de l'aperçu dira si son code a tourné. */
	ran(): void {
		this.enAttente = true;
	}

	/** Une réponse de l'aperçu ; seule celle qui suit un clic sur « Exécuter » compte. */
	response(status: number): void {
		if (!this.enAttente) return;
		this.enAttente = false;
		if (status >= 400) return;
		this.reussies++;
		if (this.reussies >= RUNS) this.show();
	}

	private show(): void {
		if (this.affiche || dejaFerme(this.offer.track)) return;
		this.affiche = true;
		const o = this.offer;
		const aside = document.createElement('aside');
		aside.className = 'scratch-offer';
		aside.setAttribute('aria-label', o.title);
		aside.innerHTML = `<p><strong>${escapeHtml(o.title)}</strong> ${escapeHtml(o.text)} <span class="scratch-offer-price">${escapeHtml(o.price)}</span>
			<a href="${escapeHtml(o.url)}">${escapeHtml(o.cta)}&nbsp;→</a></p>
			<button type="button" class="ghost small" data-close title="${escapeHtml(o.dismiss)}" aria-label="${escapeHtml(o.dismiss)}">×</button>`;
		this.host.append(aside);
		const donnees = { ...this.mesure, parcours: o.track };
		aside.querySelector('a')!.addEventListener('click', () => mesurer('bac-a-sable-offre', { ...donnees, action: 'clic' }));
		aside.querySelector('[data-close]')!.addEventListener('click', () => {
			retenir(o.track);
			aside.remove();
			mesurer('bac-a-sable-offre', { ...donnees, action: 'fermee' });
		});
		mesurer('bac-a-sable-offre', { ...donnees, action: 'vue' });
	}
}

function dejaFerme(track: string): boolean {
	try {
		return localStorage.getItem(ferme(track)) !== null;
	} catch {
		return false;
	}
}

function retenir(track: string): void {
	try {
		localStorage.setItem(ferme(track), '1');
	} catch {
		// stockage bloqué : l'encart reviendra à la prochaine visite, sans conséquence
	}
}
