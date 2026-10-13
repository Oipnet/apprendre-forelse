import { mesurer } from '../mesure';
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

/**
 * La mesure du démarrage : combien de temps il a pris, s'il a échoué, ou si le visiteur est parti avant la fin.
 * `exercice-ouvert` porte la durée (en secondes) et `premier` (l'environnement n'était pas dans le cache) ;
 * `demarrage-echoue` part quand PHP ne démarre pas ; `demarrage-interrompu` quand la page passe en arrière-plan
 * ou se ferme avant d'être prête, une fois : un onglet quitté pendant le premier téléchargement se voit enfin.
 * Un visiteur qui revient ensuite compte quand même dans `exercice-ouvert`.
 */
export function suivreDemarrage(mesure: Record<string, string>, premier: boolean, debut = performance.now()) {
	const duree = () => Math.round((performance.now() - debut) / 100) / 10;
	let interrompu = false;
	const quitte = () => {
		if (interrompu || document.visibilityState !== 'hidden') return;
		interrompu = true;
		mesurer('demarrage-interrompu', { ...mesure, premier, duree: duree() });
	};
	document.addEventListener('visibilitychange', quitte);
	const terminer = (evenement: string, donnees: Record<string, string | number | boolean> = {}) => {
		document.removeEventListener('visibilitychange', quitte);
		mesurer(evenement, { ...mesure, premier, duree: duree(), ...donnees });
	};
	return {
		pret: (donnees?: Record<string, string | number | boolean>) => terminer('exercice-ouvert', donnees),
		echec: () => terminer('demarrage-echoue'),
	};
}
