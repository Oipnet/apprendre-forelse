import { escapeHtml } from './html';

/** La clé locale qui retient qu'un environnement (à cette version d'archive) est déjà dans le cache du navigateur. */
export const bootKey = (environmentId: string) => `forelse:environnement:${environmentId}`;

/**
 * Au premier démarrage d'un environnement (ou d'une nouvelle version de son archive), le téléchargement prend du
 * temps : on le dit, et on dit que la suite ira plus vite. Null si l'archive est déjà dans le cache.
 */
export function firstLoadNote(seenArchiveUrl: string | null, archiveUrl: string): string | null {
	return seenArchiveUrl === archiveUrl ? null : 'Premier chargement de cet environnement : il se télécharge une fois (une vingtaine de secondes), puis le navigateur le garde et les exercices suivants s\'ouvrent bien plus vite.';
}

/** Ce qu'on dit quand l'exercice ne se charge pas, selon la réponse du serveur (null : pas de réponse du tout). */
export function loadErrorHtml(status: number | null, loginUrl?: string | null): string {
	const retry = '<button type="button" class="primary" data-retry>Réessayer</button>';
	const [title, detail, action] =
		status === 401
			? ['Votre session a expiré.', 'Reconnectez-vous : votre code enregistré vous attend.', loginUrl ? `<a class="button primary" href="${escapeHtml(loginUrl)}">Se reconnecter</a>` : retry]
			: status === 403
				? ['Cet exercice ne vous est plus accessible.', 'Votre accès au parcours a peut-être pris fin.', '<a class="button" href="/">Retour à l\'accueil</a>']
				: status === 404
					? ['Cet exercice est introuvable.', 'Il a peut-être été renommé ou retiré du parcours.', '<a class="button" href="/">Retour à l\'accueil</a>']
					: [
							'L\'exercice n\'a pas pu se charger.',
							status === null ? 'La connexion semble coupée.' : `Le serveur ne répond pas correctement pour l'instant (erreur ${status}).`,
							retry,
						];
	return `<div class="load-error" role="alert"><strong>${title}</strong><p>${detail}</p>${action}</div>`;
}

/** Sous le message d'échec du démarrage : réessayer, ou (si la page est montée) lire la consigne en attendant. */
export function bootFailureActionsHtml(withBrief = true): string {
	return `<p class="boot-actions"><button type="button" class="primary" data-retry>Réessayer</button>${withBrief ? ' <button type="button" class="ghost" data-read-brief>Lire la consigne</button>' : ''}</p>`;
}

/** Branche les boutons « Réessayer » (recharge la page) et « Lire la consigne » (retire l'écran de démarrage). */
export function bindRetry(root: HTMLElement, readBrief?: () => void): void {
	root.querySelectorAll<HTMLButtonElement>('[data-retry]').forEach((button) => button.addEventListener('click', () => location.reload()));
	root.querySelector<HTMLButtonElement>('[data-read-brief]')?.addEventListener('click', () => readBrief?.());
}
