import type { FrameworkProfile } from './framework.ts';

/**
 * Contrat d'exécution indépendant de la technologie : aujourd'hui des runtimes qui tournent dans
 * le navigateur (un worker), demain un conteneur serveur pour les parcours qui en ont besoin
 * (base de données, files de messages, déploiement…).
 */

/** Environnement de base d'un parcours (un framework, sa version, son lanceur de tests). */
export interface EnvironmentSpec {
	id: string;
	/**
	 * Ce que le moteur déclare du framework : console, dossiers du projet, caches, namespaces
	 * (voir App\Content\Framework\FrameworkProfile). C'est son champ `runtime` qui dit qui l'exécute,
	 * résolu par le registre des runtimes du playground.
	 */
	framework: FrameworkProfile;
	/** Options propres au runtime, qu'il est seul à lire (une version d'interpréteur…). */
	options?: Readonly<Record<string, string>>;
	/** Archive zip du projet (vendor inclus). */
	archiveUrl: string;
	/** Préfixe d'URL sous lequel l'application est servie dans l'aperçu. */
	previewBasePath: string;
	/**
	 * L'aperçu est servi en HTTPS (origine du bac à sable). L'application doit le savoir : sinon les URL
	 * absolues qu'elle produit partent en http:// et le navigateur les bloque depuis une page https
	 * (contenu mixte : feuille de style ignorée, formulaire non envoyé).
	 */
	previewSecure: boolean;
}

export interface HttpRequest {
	method: string;
	/** Chemin + query string, préfixe d'aperçu inclus (ex. /preview/menu?x=1). */
	url: string;
	headers: Record<string, string>;
	body?: Uint8Array;
}

export interface HttpResponse {
	status: number;
	headers: Record<string, string[]>;
	body: Uint8Array;
	durationMs: number;
}

export type TestStatus = 'passed' | 'failed' | 'error' | 'skipped';

export interface TestCaseResult {
	/** Classe ou suites englobantes (`describe`, séparées par « > »). */
	className: string;
	/** Nom du test : c'est lui que désigne un objectif d'exercice. */
	name: string;
	/** Fichier du test, relatif au projet. */
	file?: string;
	status: TestStatus;
	message?: string;
	/**
	 * Ce qu'on montre à l'apprenant sous un objectif manqué, tiré du message par le runtime qui sait le
	 * lire (sans en-tête ni trace). Absent : la page prend les premières lignes du message.
	 */
	summary?: string;
	timeMs: number;
}

export interface TestRunResult {
	exitCode: number;
	cases: TestCaseResult[];
	/** Sortie texte du lanceur de tests (ou erreur fatale). */
	output: string;
	durationMs: number;
}

/**
 * Notation des tests écrits par l'apprenant (même logique que content:check, côté plateforme) :
 *  - « own-tests » : ses tests passent tous sur l'application correcte (au moins un) ;
 *  - « mutant:<id> » : ils échouent sur l'application cassée par ce mutant.
 */
export interface Grading {
	ownTests: string[];
	mutants: { id: string; label: string; changes: { file: string; search: string; replace: string }[] }[];
}

export interface CommandResult {
	exitCode: number;
	/** Sortie de la commande, avec ses codes couleur ANSI. */
	output: string;
	durationMs: number;
	/** Fichiers du projet créés ou modifiés par la commande (ex. doctrine:migrations:diff). */
	fichiers?: Record<string, string>;
	/** Fichiers du projet supprimés par la commande. */
	supprimes?: string[];
}

export interface BootProgress {
	step: 'download' | 'unpack' | 'boot';
	/** Entre 0 et 1, ou null si inconnu. */
	ratio: number | null;
	label: string;
}

export interface Runtime {
	boot(env: EnvironmentSpec, onProgress?: (p: BootProgress) => void): Promise<void>;
	/** Chemins relatifs à la racine du projet (ex. src/pages/menu.vue). */
	writeFile(path: string, content: string): Promise<void>;
	/** Plusieurs fichiers d'un coup (le projet de départ) : un seul message au lieu d'un par fichier. */
	writeFiles(files: Record<string, string>): Promise<void>;
	readFile(path: string): Promise<string | null>;
	/** Tous les fichiers du projet (hors vendor/, var/ et caches), pour l'explorateur. */
	listFiles(): Promise<string[]>;
	deleteFile(path: string): Promise<void>;
	request(request: HttpRequest): Promise<HttpResponse>;
	runTests(grading?: Grading): Promise<TestRunResult>;
	/**
	 * Facultatif : la console du projet (celle que déclare le profil), arguments déjà découpés, exécutée
	 * dans le projet de l'aperçu. Un runtime sans console ne la déclare pas : la page n'offre alors ni champ
	 * de commande ni commandes de préparation, plutôt qu'une console qui échoue à chaque fois.
	 */
	runCommand?(args: string[]): Promise<CommandResult>;
	/**
	 * Facultatif : prévient quand le runtime a dû être relancé parce qu'il ne répondait plus (une boucle
	 * infinie dans le code de l'apprenant). Les fichiers sont rejoués ; ce qui ne vivait qu'en mémoire
	 * (base de l'aperçu, sessions) est perdu.
	 */
	onRestart?(listener: (event: RuntimeRestart) => void): void;
}

export type RuntimeRestart =
	/** Le runtime vient d'être arrêté ; les appels en cours ont échoué avec `reason`. */
	| { phase: 'restarting'; reason: string }
	/** Un runtime neuf a démarré, avec les fichiers du projet. */
	| { phase: 'restarted' }
	/** Le nouveau runtime n'a pas démarré : il faut recharger la page. */
	| { phase: 'failed'; error: string };
