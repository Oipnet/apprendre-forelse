/**
 * Mesure d'audience : un événement envoyé au traceur Umami du site, quand il est là.
 *
 * Rien n'est envoyé sans lui — instance auto-hébergée sans mesure, bloqueur de publicités, script pas encore
 * chargé : la page doit fonctionner pareil. Les événements ne portent que du contenu public (identifiant
 * d'exercice, de parcours), jamais ce que l'apprenant écrit ni qui il est.
 */
type Umami = { track: (name: string, data?: Record<string, string | number | boolean>) => void };

type Evenement = [nom: string, donnees?: Record<string, string | number | boolean>];

/** Événements mesurés avant le chargement du traceur (voir poserTraceur) ; null quand rien n'est attendu. */
let enAttente: Evenement[] | null = null;

export function mesurer(evenement: string, donnees?: Record<string, string | number | boolean>): void {
	const umami = (window as { umami?: Umami }).umami;
	if (!umami) {
		enAttente?.push([evenement, donnees]);
		return;
	}
	try {
		umami.track(evenement, donnees);
	} catch {
		// La mesure ne casse jamais la page.
	}
}

/** Les gestes d'un visiteur. Un robot qui affiche la page puis repart n'en fait aucun. */
// Pas « scroll » : la page (éditeur, restauration de la position) peut défiler toute seule, sans visiteur.
const GESTES = ['pointerdown', 'pointermove', 'touchstart', 'wheel', 'keydown'] as const;

/**
 * Pose le traceur d'Umami décrit par la balise `<meta name="mesure-audience">` de _head.html.twig, sauf dans un
 * navigateur piloté par un robot (`navigator.webdriver`) : les robots qui ouvrent un lien juste après LinkedIn
 * faisaient des visites, et des démarrages de playground ratés, qu'aucun visiteur n'a vécus.
 *
 * Le traceur n'est chargé qu'au premier geste (molette, toucher, souris, clavier) : des robots font tourner un
 * vrai Chrome sans `navigator.webdriver` (1 page par visite, ni geste ni événement) et faisaient la majorité des
 * visites comptées. La page vue part donc à ce moment-là, et les événements mesurés d'ici là attendent le traceur.
 * Rien de plus n'est collecté : le geste n'est pas envoyé. Un visiteur qui repart sans rien toucher n'est pas compté.
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
	// Les événements annoncés par le serveur (balises `<meta name="mesure-evenement">`, ex. « compte-cree » juste
	// après l'inscription) partent une fois le traceur chargé : avant, window.umami n'existe pas.
	const attente: Evenement[] = [...doc.querySelectorAll<HTMLMetaElement>('meta[name="mesure-evenement"]')]
		.map((meta) => meta.content)
		.filter(Boolean)
		.map((nom): Evenement => [nom]);
	enAttente = attente;
	script.addEventListener(
		'load',
		() => {
			enAttente = null;
			attente.forEach(([nom, donnees]) => mesurer(nom, donnees));
		},
		{ once: true },
	);
	const fenetre = doc.defaultView ?? window;
	const poser = () => {
		GESTES.forEach((geste) => fenetre.removeEventListener(geste, poser, true));
		doc.head.append(script);
	};
	GESTES.forEach((geste) => fenetre.addEventListener(geste, poser, { capture: true, passive: true }));
	return script;
}
