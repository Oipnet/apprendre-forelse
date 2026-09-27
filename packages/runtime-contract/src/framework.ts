/**
 * L'identifiant d'un framework, tel qu'un environment.yaml l'écrit.
 *
 * Volontairement ouvert : un framework ajouté par un paquet tiers n'a pas à figurer dans une énumération
 * du moteur. Ceux que le moteur livre sont « symfony », « laravel », « docker » et « nuxt ».
 */
export type FrameworkId = string;

/**
 * Ce que le moteur déclare d'un framework, servi dans la charge utile de l'exercice.
 *
 * Le playground ne redéclare rien de son côté : ce qui relève de la donnée — la console, les dossiers,
 * les familles d'extraits, les alias de commande — est déclaré une fois côté moteur
 * (App\Content\Framework\FrameworkProfile) et voyage jusqu'ici.
 */
export interface FrameworkProfile {
	id: FrameworkId;
	/** Nom affiché (« Symfony »). */
	label: string;
	/** La console du projet, telle qu'un développeur la tape (« bin/console »). */
	console: string;
	/** Commande d'exemple, en filigrane du champ de la console. */
	consoleExample: string;
	/** Ce qu'on explique à l'apprenant pendant le démarrage. */
	bootNote: string;
	/** Étape de démarrage : « Décompression du projet Symfony ». */
	unpackLabel: string;
	/** Identifiant du lanceur de tests (« vitest »…), tel que le moteur le déclare. */
	testRunner: string;
	/** Nom du lanceur de tests, tel qu'on l'affiche (« Vitest ») : la sortie des tests porte son nom. */
	testRunnerLabel: string;
	/**
	 * Qui l'exécute dans le navigateur : « nuxt-sim », ou tout autre runtime enregistré.
	 * Résolu par src/runtime/registry.ts — le playground ne connaît plus les frameworks par leur nom.
	 */
	runtime: string;
	/** Familles d'extraits que l'éditeur propose. */
	snippets: string[];
	/** Préfixes tolérés au début d'une commande => ce qui les remplace (chaîne vide : retiré). */
	consoleAliases: Record<string, string>;
	/** Dossiers du projet, visibles dans l'explorateur. */
	projectDirs: string[];
	/** Caches à vider entre deux runs de tests. */
	testCaches: string[];
	/** Dossiers masqués dans l'explorateur. */
	hidden: string[];
	/** Premier dossier d'un chemin => racine de namespace (« src » => « App »). */
	namespaceRoots: Record<string, string>;
}
