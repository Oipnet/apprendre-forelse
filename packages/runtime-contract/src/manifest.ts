import type { Runtime } from './runtime';

/**
 * Ce qu'un paquet de runtime déclare au playground.
 *
 * Le module désigné par `forelse.runtime` dans le `package.json` du paquet exporte ce manifeste par
 * défaut. Le playground le découvre parmi ses dépendances et l'enregistre seul : ajouter un runtime ne
 * demande de modifier aucun fichier du moteur.
 */
export interface RuntimeManifest {
	/** L'identifiant qu'un profil de framework déclare (`runtime:` côté moteur). */
	id: string;
	/** Comment on le nomme à l'apprenant quand il refuse de démarrer (« PHP », « le simulateur Nuxt »). */
	label: string;
	/** Construit une instance. Appelée une fois par exercice ouvert. */
	create: () => Runtime;
}

/**
 * Ce qu'un paquet de runtime demande au build, quand son worker a besoin de plus que du TypeScript.
 *
 * Déclaré dans le module désigné par `forelse.vite` : le playground l'ajoute à sa configuration sans
 * rien savoir de ce qu'il contient. Le simulateur Nuxt s'en sert pour compiler son runtime navigateur
 * et poser les drapeaux de compilation de Vue.
 *
 * Le type est volontairement lâche (`unknown[]` plutôt que `Plugin[]`) : le contrat ne dépend pas de
 * Vite, pour qu'un changement de bundler n'oblige pas à le republier.
 */
export interface RuntimeBuildContribution {
	/** Greffons du bundler, ajoutés à ceux du playground et à ceux de ses workers. */
	plugins?: () => unknown[];
	/** Constantes remplacées à la compilation (`define` chez Vite). */
	define?: Record<string, string>;
	/** Alias de résolution, si le runtime en a besoin. */
	alias?: { find: string | RegExp; replacement: string }[];
}
