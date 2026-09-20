import type { FrameworkProfile } from '../app/types.ts';

/**
 * Contrat d'exécution indépendant de la technologie : aujourd'hui php-wasm dans
 * le navigateur (WasmRuntime), demain un conteneur serveur pour les parcours
 * qui en ont besoin (MySQL, Messenger, déploiement…).
 */

/** Environnement de base d'un parcours (ex. Symfony 8 + Twig + PHPUnit). */
export interface EnvironmentSpec {
	id: string;
	/**
	 * Ce que le moteur déclare du framework : console, dossiers du projet, caches, namespaces
	 * (voir App\Content\Framework\FrameworkProfile). Son `id` choisit le runtime : php-wasm pour
	 * Symfony, Laravel et le simulateur Docker ; le simulateur Nuxt pour Nuxt.
	 */
	framework: FrameworkProfile;
	/** Vide pour Nuxt (simulateur JavaScript, sans PHP). */
	phpVersion: '8.4' | '';
	/** Archive zip du projet (vendor inclus). */
	archiveUrl: string;
	/** Préfixe d'URL sous lequel l'application est servie dans l'aperçu. */
	previewBasePath: string;
	/**
	 * L'aperçu est servi en HTTPS (origine du bac à sable). PHP doit le savoir : sinon les URL absolues
	 * qu'il produit (Laravel `asset()`, `route()`, Symfony `url()`) partent en http:// et le navigateur
	 * les bloque depuis une page https (contenu mixte : feuille de style ignorée, formulaire non envoyé).
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
	className: string;
	name: string;
	/** Fichier du test, relatif au projet (tests/…). */
	file?: string;
	status: TestStatus;
	message?: string;
	timeMs: number;
}

export interface TestRunResult {
	exitCode: number;
	cases: TestCaseResult[];
	/** Sortie texte brute de PHPUnit (ou erreur fatale). */
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
	/** Chemins relatifs à la racine du projet (ex. src/Controller/MenuController.php). */
	writeFile(path: string, content: string): Promise<void>;
	readFile(path: string): Promise<string | null>;
	/** Tous les fichiers du projet (hors vendor/, var/ et caches), pour l'explorateur. */
	listFiles(): Promise<string[]>;
	deleteFile(path: string): Promise<void>;
	request(request: HttpRequest): Promise<HttpResponse>;
	runTests(grading?: Grading): Promise<TestRunResult>;
	/** Commande bin/console (arguments déjà découpés), exécutée dans le projet de l'aperçu. */
	runCommand(args: string[]): Promise<CommandResult>;
}
