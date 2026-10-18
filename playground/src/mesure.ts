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

/**
 * Pose le traceur d'Umami décrit par la balise `<meta name="mesure-audience">` de _head.html.twig, sauf dans un
 * navigateur piloté par un robot (`navigator.webdriver`) : les robots qui ouvrent un lien juste après LinkedIn
 * faisaient des visites, et des démarrages de playground ratés, qu'aucun visiteur n'a vécus.
 *
 * Le traceur lit sa configuration sur sa propre balise (data-website-id…) et envoie à côté de son src : on les
 * recopie telles quelles.
 */
export function poserTraceur(doc: Document = document, nav: Navigator = navigator): HTMLScriptElement | null {
	const config = doc.querySelector<HTMLMetaElement>('meta[name="mesure-audience"]');
	const { src, ...attributs } = config?.dataset ?? {};
	if (!src || nav.webdriver) return null;
	const script = doc.createElement('script');
	script.defer = true;
	script.src = src;
	Object.assign(script.dataset, attributs);
	doc.head.append(script);
	return script;
}
