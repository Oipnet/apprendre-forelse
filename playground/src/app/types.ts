import type { Grading } from '../runtime/Runtime';

/** Exercice tel que servi par GET /api/exercises/{track}/{exercise} (ou /api/exercises/pratique/{exercise}). */
export interface ExercisePayload {
	id: string;
	/** null pour un exercice de Pratique, hors parcours. */
	trackId: string | null;
	title: string;
	concepts: string[];
	xp: number;
	access: 'free' | 'account';
	open: string;
	preview: string;
	editable: string[];
	readonly: string[];
	objectives: { test: string; label: string }[];
	hints: string[];
	/** Markdown. */
	instructions: string;
	/** Commandes bin/console lancées au chargement (ex. doctrine:schema:update --force). */
	setup: string[];
	/** Tests écrits par l'apprenant, et mutants qu'ils doivent détecter (voir Grading). */
	ownTests: string[];
	mutants: Grading['mutants'];
	/** Documentation utile pour résoudre l'exercice (URL http(s), vérifiées côté plateforme). */
	docs: { title: string; url: string }[];
	/** Requêtes d'exemple de l'onglet « Requêtes » (API JSON…). */
	requests: ExampleRequest[];
	/** État de départ, par chemin relatif au projet. */
	files: Record<string, string>;
	/** Tests cachés (tests/…). */
	tests: Record<string, string>;
	environment: {
		id: string;
		phpVersion: '8.4' | '';
		/** Ce que le moteur sait de ce framework (voir App\Content\Framework\FrameworkProfile). */
		framework: FrameworkProfile;
		archiveUrl: string;
		completionIndexUrl: string;
	};
	next: { id: string; title: string; url: string } | null;
	/** Sur le dernier exercice d'un parcours : le parcours conseillé ensuite. */
	nextTrack: { id: string; title: string; description: string; url: string } | null;
	/** Fiche de cours que la réussite de cet exercice débloque (dernier exercice de son chapitre). */
	lesson: { title: string; url: string } | null;
	/** Exercice de Pratique : d'où vient la fonctionnalité (version du framework, pull request). */
	practice: { framework: string; version: string | null; pullRequest: string | null; published: string } | null;
	/** Présent uniquement en environnement de développement de la plateforme. */
	solution?: Record<string, string>;
}

export interface ExampleRequest {
	title: string;
	method: string;
	path: string;
	headers: Record<string, string>;
	/** Peut contenir {{ date:+N }} (date du jour + N jours). */
	body: string | null;
}

/** Les identifiants de framework que le playground sait exécuter : à chacun son runtime. */
export type FrameworkId = 'symfony' | 'laravel' | 'docker' | 'nuxt';

/**
 * Ce que le moteur déclare d'un framework, servi dans la charge utile de l'exercice : le playground ne
 * redéclare plus rien de son côté. Le code, lui (worker, snippets, script de console), reste ici.
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
	testRunner: 'phpunit' | 'vitest';
	/** Dossiers du projet, visibles dans l'explorateur. */
	projectDirs: string[];
	/** Caches à vider entre deux runs de tests. */
	testCaches: string[];
	/** Dossiers masqués dans l'explorateur. */
	hidden: string[];
	/** Premier dossier d'un chemin => racine de namespace (« src » => « App »). */
	namespaceRoots: Record<string, string>;
}

/** Configuration passée par la page Twig (attribut data-config). */
export interface PlaygroundConfig {
	exerciseUrl: string;
	sandboxUrl: string;
	/** L'identité de l'instance, pour la barre du haut (voir App\Instance\Branding). */
	brand: { name: string; chip: string; title: string; logoUrl: string | null };
	/** Exercice d'un parcours, ou de la Pratique (sans suite, sans XP, sans fiche de cours). */
	context: 'track' | 'practice';
	/** Le lien de retour de la barre : le parcours, ou la liste de la Pratique. */
	back: { title: string; url: string };
	progress: { mode: 'api'; url: string } | { mode: 'local' };
	/** L'apprenant connecté ; null pour un invité. */
	user: { name: string; xp: number } | null;
	loginUrl?: string | null;
	/** Pour inviter l'invité à créer un compte après une réussite. */
	registerUrl?: string | null;
	/** Bêta fermée : la liste d'attente, pour l'invité qui n'a pas de code d'invitation. */
	waitlistUrl?: string | null;
	/** Où envoyer un avis sur l'exercice (POST JSON) ; null pour un invité. */
	feedbackUrl?: string | null;
	/** Le mentor (revue de code, erreurs expliquées) ; null pour un invité ou sans clé d'API côté serveur. */
	mentor?: { reviewUrl: string; explainUrl: string } | null;
}
