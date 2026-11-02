import { m } from '../i18n';
import { mesurer } from '../mesure';
import { escapeHtml } from './html';
import { canShareNatively } from './share';

/**
 * Sur un téléphone, une boîte propose de reprendre l'exercice sur ordinateur : on s'envoie le lien (email, partage
 * du téléphone, copie), ou on continue ici. Beaucoup de visites arrivent de LinkedIn sur un téléphone et repartent
 * avant que l'environnement ait démarré : elles repartent maintenant avec le lien.
 *
 * Le playground démarre derrière, sans attendre. « Continuer sur le téléphone » est retenu : la boîte ne revient plus.
 * Le lien envoyé garde son fragment (le code d'un bac à sable) et dit d'où viendra la visite (utm_source=rappel).
 */
const VU = 'forelse:mobile-continuer-ici';
/** Petit écran et doigt : un ordinateur à fenêtre étroite n'est pas concerné. */
const TELEPHONE = '(max-width: 800px) and (pointer: coarse)';

export function suggestDesktop(host: HTMLElement, title: string, mesure: Record<string, string>): void {
	if (!matchMedia(TELEPHONE).matches || dejaVu()) return;
	const url = new URL(location.href);
	url.searchParams.set('utm_source', 'rappel');
	url.searchParams.set('utm_medium', 'mobile');
	url.searchParams.set('utm_campaign', 'continuer-sur-ordinateur');
	const link = url.toString();
	const mailto = `mailto:?subject=${encodeURIComponent(m.mobile.subject(title))}&body=${encodeURIComponent(`${m.mobile.body}\n${link}`)}`;

	const dialog = document.createElement('dialog');
	dialog.className = 'feedback mobile-desktop';
	dialog.setAttribute('aria-labelledby', 'mobile-desktop-title');
	dialog.innerHTML = `<h2 id="mobile-desktop-title">${m.mobile.title}</h2>
		<p>${m.mobile.detail}</p>
		<p class="mobile-desktop-actions">
			<a class="button primary" href="${escapeHtml(mailto)}" data-choix="email">${m.mobile.email}</a>
			${canShareNatively() ? `<button type="button" class="ghost" data-choix="partage">${m.mobile.share}</button>` : ''}
			<button type="button" class="ghost" data-choix="copie">${m.mobile.copy}</button>
		</p>
		<p class="share-status" role="status"></p>
		<p class="confirm-actions"><button type="button" class="ghost" data-choix="continuer">${m.mobile.stay}</button></p>`;
	host.append(dialog);
	const status = dialog.querySelector<HTMLElement>('.share-status')!;
	const choisir = (choix: string) => mesurer('mobile-ordinateur', { ...mesure, choix });

	dialog.querySelector('[data-choix="email"]')!.addEventListener('click', () => choisir('email'));
	dialog.querySelector('[data-choix="partage"]')?.addEventListener('click', async () => {
		try {
			await navigator.share({ title, url: link });
			choisir('partage');
		} catch {
			// partage fermé sans rien envoyer : la boîte reste
		}
	});
	dialog.querySelector('[data-choix="copie"]')!.addEventListener('click', async () => {
		try {
			await navigator.clipboard.writeText(link);
			status.textContent = m.mobile.copied;
		} catch {
			status.textContent = link; // presse-papiers refusé : le lien s'affiche, à copier à la main
		}
		choisir('copie');
	});
	dialog.querySelector('[data-choix="continuer"]')!.addEventListener('click', () => {
		retenir();
		choisir('continuer');
		dialog.close();
	});
	dialog.addEventListener('close', () => dialog.remove());
	dialog.showModal();
	mesurer('mobile-ordinateur', { ...mesure, choix: 'affiche' });
}

function dejaVu(): boolean {
	try {
		return localStorage.getItem(VU) !== null;
	} catch {
		return false;
	}
}

function retenir(): void {
	try {
		localStorage.setItem(VU, '1');
	} catch {
		// stockage bloqué : la boîte reviendra au prochain exercice
	}
}
