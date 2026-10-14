import { m } from '../i18n';
import { mesurer } from '../mesure';
import { escapeHtml } from './html';

/** La clé locale qui retient qu'un environnement (à cette version d'archive) est déjà dans le cache du navigateur. */
export const bootKey = (environmentId: string) => `forelse:environnement:${environmentId}`;

/**
 * Au premier démarrage d'un environnement (ou d'une nouvelle version de son archive), le téléchargement prend du
 * temps : on le dit, et on dit que la suite ira plus vite. Null si l'archive est déjà dans le cache.
 */
export function firstLoadNote(seenArchiveUrl: string | null, archiveUrl: string): string | null {
	return seenArchiveUrl === archiveUrl ? null : m.boot.firstLoad;
}

/** Ce qu'on dit quand l'exercice ne se charge pas, selon la réponse du serveur (null : pas de réponse du tout). */
export function loadErrorHtml(status: number | null, loginUrl?: string | null): string {
	const retry = `<button type="button" class="primary" data-retry>${m.boot.retry}</button>`;
	const home = `<a class="button" href="/">${m.boot.backHome}</a>`;
	const [title, detail, action] =
		status === 401
			? [m.boot.sessionExpired, m.boot.sessionExpiredDetail, loginUrl ? `<a class="button primary" href="${escapeHtml(loginUrl)}">${m.boot.relogin}</a>` : retry]
			: status === 403
				? [m.boot.forbidden, m.boot.forbiddenDetail, home]
				: status === 404
					? [m.boot.notFound, m.boot.notFoundDetail, home]
					: [m.boot.loadFailed, status === null ? m.boot.offline : m.boot.serverError(status), retry];
	return `<div class="load-error" role="alert"><strong>${title}</strong><p>${detail}</p>${action}</div>`;
}

/** Sous le message d'échec du démarrage : réessayer, ou (si la page est montée) lire la consigne en attendant. */
export function bootFailureActionsHtml(withBrief = true): string {
	return `<p class="boot-actions"><button type="button" class="primary" data-retry>${m.boot.retry}</button>${withBrief ? ` <button type="button" class="ghost" data-read-brief>${m.boot.readBrief}</button>` : ''}</p>`;
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
 * `demarrage-echoue` porte aussi la `raison` (première ligne de l'erreur) : sans elle, un échec chez un visiteur
 * restait inexplicable, faute de pouvoir le reproduire.
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
		echec: (erreur?: unknown) => terminer('demarrage-echoue', erreur === undefined ? {} : { raison: raisonDeLEchec(erreur) }),
	};
}

/**
 * La raison d'un échec, telle que la mesure peut la porter : la première ligne du message, sans les paramètres des
 * URL (une archive de parcours est servie par une URL signée) et courte.
 */
export function raisonDeLEchec(erreur: unknown): string {
	const message = erreur instanceof Error ? `${erreur.name}: ${erreur.message}` : String(erreur);
	return message.split('\n')[0].replace(/(https?:\/\/[^\s?]*)\?\S*/g, '$1').slice(0, 200);
}
