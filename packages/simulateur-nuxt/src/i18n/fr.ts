/**
 * Les textes du simulateur Nuxt montrés à l'apprenant, en français : la langue de référence.
 *
 * Un objet par module. Une valeur est une chaîne, ou une fonction quand le texte a des paramètres. Une phrase
 * reste entière dans un seul message. Les messages qui imitent Nuxt, Vitest ou Node (« Page not found »,
 * « fetch failed »…) n'y sont pas : ils restent ceux de l'outil imité.
 *
 * Une autre langue s'écrit `export const en: Messages = { … }` (voir index.ts) : tsc vérifie qu'elle est complète.
 */
export const fr = {
	/** Le runtime vu du playground. */
	runtime: {
		/** Commence une phrase (« Le simulateur Nuxt ne répond plus… ») : voir WorkerRuntime. */
		label: 'Le simulateur Nuxt',
		/** Au milieu d'une phrase (« Impossible de démarrer le simulateur Nuxt : … ») : voir RuntimeManifest. */
		name: 'le simulateur Nuxt',
	},

	/** Le démarrage du worker : la barre de progression, puis ses erreurs. */
	boot: {
		downloading: 'Téléchargement du projet Nuxt…',
		unpacking: 'Ouverture du projet…',
		booting: 'Démarrage du simulateur Nuxt…',
		archiveNotFound: (status: number) => `Archive de l'environnement introuvable (${status}).`,
		notStarted: 'Simulateur Nuxt non démarré.',
	},

	/** Le chargement des modules du projet (rendu, routes, tests). */
	modules: {
		fileNotFound: (path: string) => `Fichier introuvable : ${path}`,
		/** Un paquet npm que le simulateur n'embarque pas (`from` : le fichier qui l'importe). */
		unsupportedPackage: (specifier: string, from: string) => `Le simulateur ne fournit pas le paquet « ${specifier} » (importé par ${from})`,
	},

	/** Le réseau du bac à sable (`file` : reseau.json). Ces textes sont la cause d'un « fetch failed ». */
	network: {
		noInternet: (url: string, file: string) => `Le bac à sable n'a pas accès à Internet : aucune réponse enregistrée pour ${url} dans ${file}.`,
		connectionLost: (url: string) => `Connexion interrompue : ${url} ne répond pas.`,
		invalidFile: (file: string, error: string) => `${file} n'est pas du JSON valide : ${error}`,
	},

	/** Les tests Vitest de l'exercice, et la notation des tests de l'apprenant. */
	tests: {
		noOwnTest: 'Aucun de vos tests ne s\'est exécuté : écrivez au moins un it(…) dans votre fichier de test.',
		skipped: '(ignoré)',
		/** Dans la ligne « Tests » du rapport, quand aucun test n'a tourné. */
		none: 'aucun test',
		nuxtEnvironmentUnavailable: 'l\'environnement de test « nuxt » n\'est pas disponible ici.',
		createPage: 'createPage : pas de navigateur piloté dans le simulateur (Playwright).',
		renderSuspended: 'renderSuspended demande @testing-library/vue, que le simulateur ne fournit pas : utilisez mountSuspended.',
		mountSuspendedSpy: 'mountSuspended({ spy: true }) : vi.mockObject n\'est pas disponible dans le simulateur.',
		viMock: 'vi.mock n\'est pas disponible dans le simulateur : pour une fonction de Nuxt, utilisez mockNuxtImport (@nuxt/test-utils/runtime).',
	},
};
