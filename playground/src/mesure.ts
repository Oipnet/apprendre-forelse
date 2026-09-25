/**
 * Mesure d'audience : un événement envoyé au traceur Umami du site, quand il est là.
 *
 * Rien n'est envoyé sans lui — instance auto-hébergée sans mesure, bloqueur de publicités, script pas encore
 * chargé : la page doit fonctionner pareil. Les événements ne portent que du contenu public (identifiant
 * d'exercice, de parcours), jamais ce que l'apprenant écrit ni qui il est.
 */
type Umami = { track: (name: string, data?: Record<string, string | number | boolean>) => void };

export function mesurer(evenement: string, donnees?: Record<string, string | number | boolean>): void {
	try {
		(window as { umami?: Umami }).umami?.track(evenement, donnees);
	} catch {
		// La mesure ne casse jamais la page.
	}
}
