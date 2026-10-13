import { mesurer } from '../mesure';
import { escapeHtml } from './html';

/**
 * Le partage d'une page (contrat : App\Share\ShareLinks) : des liens vers LinkedIn, X et Bluesky, la copie du lien,
 * et le partage du téléphone quand le navigateur le propose. Le même code sert aux pages du site
 * (share/_buttons.html.twig, branché par site.ts) et au playground (barre du haut, panneau de réussite).
 */
export interface ShareLinks {
	/** Le texte proposé avec le lien. */
	text: string;
	linkedin: string;
	x: string;
	bluesky: string;
	/** L'adresse à copier, et celle du partage du téléphone : chacune dit d'où viendra la visite (utm_source). */
	lien: string;
	natif: string;
}

const NETWORKS = [
	['linkedin', 'LinkedIn'],
	['x', 'X'],
	['bluesky', 'Bluesky'],
] as const;

/** Les boutons, dans les classes du playground. Le bouton du téléphone reste caché tant que mountShare ne l'a pas vu possible. */
export function shareHtml(links: ShareLinks, title: string): string {
	return `<div class="share" data-share>
		<span class="share-title">${escapeHtml(title)} :</span>
		<button type="button" class="ghost small" data-share-native hidden>Partager…</button>
		${NETWORKS.map(([key, label]) => `<a class="button ghost small" href="${escapeHtml(links[key])}" target="_blank" rel="noopener noreferrer" data-share-network="${key}">${label}</a>`).join('')}
		<button type="button" class="ghost small" data-share-copy>Copier le lien</button>
		<span class="share-status" role="status"></span>
	</div>`;
}

/** Le partage du téléphone, quand le navigateur en a un. */
export function canShareNatively(): boolean {
	return typeof navigator.share === 'function';
}

/** Ouvre le partage du téléphone ; faux si le visiteur l'a fermé sans partager, ou si le navigateur refuse. */
export async function shareNatively(links: Pick<ShareLinks, 'text' | 'natif'>, mesure: Record<string, string> = {}): Promise<boolean> {
	try {
		await navigator.share({ text: links.text, url: links.natif });
		mesurer('partage', { ...mesure, reseau: 'natif' });
		return true;
	} catch {
		return false;
	}
}

/** Branche les boutons d'un bloc de partage : copie du lien, partage du téléphone, mesure des clics. */
export function mountShare(root: HTMLElement, links: Pick<ShareLinks, 'text' | 'lien' | 'natif'>, mesure: Record<string, string> = {}): void {
	const status = root.querySelector<HTMLElement>('.share-status');
	const native = root.querySelector<HTMLButtonElement>('[data-share-native]');
	if (native && canShareNatively()) {
		native.hidden = false;
		native.addEventListener('click', () => void shareNatively(links, mesure));
	}
	root.querySelector<HTMLButtonElement>('[data-share-copy]')?.addEventListener('click', async () => {
		try {
			await navigator.clipboard.writeText(links.lien);
			if (status) status.textContent = 'Lien copié.';
			mesurer('partage', { ...mesure, reseau: 'lien' });
		} catch {
			// Presse-papiers refusé (page non sécurisée, permission) : le lien s'affiche, à copier à la main.
			if (status) status.textContent = links.lien;
		}
	});
	for (const link of root.querySelectorAll<HTMLAnchorElement>('a[data-share-network]')) {
		link.addEventListener('click', () => mesurer('partage', { ...mesure, reseau: link.dataset.shareNetwork! }));
	}
}

/** Une boîte de partage (« Partager cet exercice »), avec les mêmes boutons que la page. */
export function shareDialog(host: HTMLElement, links: ShareLinks, title: string, mesure: Record<string, string> = {}): void {
	const dialog = document.createElement('dialog');
	dialog.className = 'feedback share-dialog';
	dialog.setAttribute('aria-labelledby', 'share-dialog-title');
	dialog.innerHTML = `<h2 id="share-dialog-title">${escapeHtml(title)}</h2>
		${shareHtml(links, 'Partager sur')}
		<p class="confirm-actions"><button type="button" class="ghost" data-close>Fermer</button></p>`;
	host.append(dialog);
	mountShare(dialog.querySelector<HTMLElement>('[data-share]')!, links, mesure);
	dialog.querySelector('[data-close]')!.addEventListener('click', () => dialog.close());
	dialog.addEventListener('close', () => dialog.remove());
	dialog.showModal();
}
