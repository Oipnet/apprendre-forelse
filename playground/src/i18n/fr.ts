/**
 * Les textes du playground montrés à l'apprenant ou au visiteur, en français : la langue de référence.
 *
 * Un objet par module. Une valeur est une chaîne, ou une fonction quand le texte a des paramètres (nombre,
 * titre, pluriel). Une phrase reste entière dans un seul message : quand elle porte un lien ou une balise,
 * le message reçoit le morceau de HTML déjà prêt (paramètre `lien`, `parcours`…), ou une fonction qui enrobe
 * le texte. Les paramètres insérés dans du HTML arrivent déjà échappés.
 *
 * Une autre langue s'écrit `export const en: Messages = { … }` (voir index.ts) : tsc vérifie qu'elle est complète.
 */
export const fr = {
	common: {
		xp: (xp: number) => `${xp} XP`,
		close: 'Fermer',
		cancel: 'Annuler',
		send: 'Envoyer',
		sending: 'Envoi…',
	},

	boot: {
		firstLoad: 'Premier chargement de cet environnement : il se télécharge une fois (une vingtaine de secondes), puis le navigateur le garde et les exercices suivants s\'ouvrent bien plus vite.',
		retry: 'Réessayer',
		readBrief: 'Lire la consigne',
		backHome: 'Retour à l\'accueil',
		sessionExpired: 'Votre session a expiré.',
		sessionExpiredDetail: 'Reconnectez-vous : votre code enregistré vous attend.',
		relogin: 'Se reconnecter',
		forbidden: 'Cet exercice ne vous est plus accessible.',
		forbiddenDetail: 'Votre accès au parcours a peut-être pris fin.',
		notFound: 'Cet exercice est introuvable.',
		notFoundDetail: 'Il a peut-être été renommé ou retiré du parcours.',
		loadFailed: 'L\'exercice n\'a pas pu se charger.',
		offline: 'La connexion semble coupée.',
		serverError: (status: number) => `Le serveur ne répond pas correctement pour l'instant (erreur ${status}).`,
		/** Le runtime n'a pas démarré (son nom : PHP, le simulateur Nuxt…). */
		runtimeFailed: (runtime: string, message: string) => `Impossible de démarrer ${runtime} : ${message}`,
		startFailed: 'Démarrage impossible',
		/** La mise en place a échoué ailleurs qu'au démarrage du runtime. */
		openFailed: (message: string) => `L'exercice n'a pas pu s'ouvrir : ${message}`,
		/** Une commande de préparation (ex. « bin/console doctrine:schema:update --force »). */
		preparing: (command: string) => `Préparation : ${command}`,
	},

	playground: {
		previewFrozen: 'La page de l\'aperçu ne répond plus : boucle infinie dans son code ?',
		previewFrozenConsole: 'La page de l\'aperçu ne répond plus depuis plusieurs secondes : boucle infinie dans son code (onMounted, watch, gestionnaire d\'événement…), ou boîte de dialogue (alert, confirm) restée ouverte ? L\'aperçu a été relancé sans recharger cette page. Corrigez la boucle, puis rechargez l\'aperçu (⟳).',
		previewRecovered: 'Aperçu relancé. Corrigez la boucle, puis rechargez-le (⟳).',
		previewRestartFailed: 'L\'aperçu n\'a pas pu être relancé : rechargez-le (⟳) pour réessayer.',
		previewProblem: 'La page de l\'aperçu a signalé un problème',
		/** Lu par un lecteur d'écran sur la rail de l'aperçu replié, après son nom. */
		previewProblemSpoken: 'la page de l\'aperçu a signalé un problème',
		setupFailedSpoken: 'une commande de préparation a échoué',
		restarting: 'Le code ne répond plus (boucle infinie ?) : redémarrage…',
		restartFailed: (error: string) => `Impossible de redémarrer : ${error}. Rechargez la page.`,
		restarted: 'Redémarré. Corrigez la boucle, puis relancez.',
		draftSaved: 'Brouillon enregistré',
		draftNotSaved: (message: string) => `Brouillon non enregistré : ${message}`,
		testsRunning: 'Tests en cours…',
		testsDone: (passed: number, total: number, ms: number) => `${passed}/${total} objectifs · tests en ${ms} ms`,
		testsError: 'Erreur pendant les tests',
		/** `mod` : « Ctrl » ou « ⌘ ». */
		runTitle: (mod: string) => `Lancer les tests (${mod} + Entrée)`,
		ready: (seconds: number) => `Prêt en ${seconds.toFixed(1)} s`,
	},

	layout: {
		doneChipTitle: 'Exercice réussi',
		doneChip: 'Réussi',
		paneSwitch: 'Volet affiché',
		brief: 'Consignes',
		code: 'Code',
		preview: 'Aperçu',
		starting: 'Démarrage…',
		userTitle: 'Connecté·e',
		guestTitle: 'Progression enregistrée dans ce navigateur',
		guest: 'Invité',
		login: 'Connexion',
		shareTitle: 'Partager cet exercice',
		share: 'Partager',
		feedbackTitle: 'Signaler un problème ou donner votre avis sur cet exercice',
		feedback: 'Un avis ?',
		solution: 'Solution',
		resetTitle: 'Revenir au code de départ',
		reset: 'Réinitialiser',
		shortcuts: 'Raccourcis clavier',
		/** Le bouton des tests : `masquable` enrobe ce qui disparaît sur un écran étroit. */
		run: (masquable: (texte: string) => string) => `${masquable('Lancer les ')}tests`,
		smallScreen: 'Cet exercice se joue mieux sur un écran large : ici, un volet à la fois.',
		inAppBrowser: 'Le navigateur de l\'application démarre l\'exercice lentement : ouvrez cette page dans votre navigateur (menu ⋯ › Ouvrir dans le navigateur).',
		versionTitle: 'Les autres exercices de cette version',
		objectives: 'Objectifs',
		docs: 'La doc qui aide',
		/** `runner` : PHPUnit, Vitest… */
		testOutput: (runner: string) => `Sortie ${runner}`,
		explainTitle: 'Demander au mentor ce que signifie cette erreur',
		explainError: 'Expliquer l\'erreur',
		explain: 'Expliquer',
		treeTitle: 'Afficher ou masquer les fichiers du projet',
		tree: 'Fichiers du projet',
		project: 'Projet',
		newFile: 'Créer un fichier',
		console: 'Console',
		requests: 'Requêtes',
		database: 'Base',
		databaseNote: 'La base de l\'aperçu, lue sans passer par l\'application.',
		databaseRefresh: 'Relire la base',
		commandFailed: 'Une commande a échoué',
		sandboxTitle: 'L\'application tourne dans un bac à sable isolé',
		sandbox: 'aperçu',
		previewPath: 'Chemin de l\'aperçu',
		reloadTitle: 'Recharger',
		reload: 'Recharger l\'aperçu',
		previewFrozen: 'La page ne répondait plus : boucle infinie dans son code, ou boîte de dialogue restée ouverte ? L\'aperçu a été relancé sans la recharger. Corrigez la boucle, puis rechargez (⟳).',
		previewFrame: 'Aperçu de l\'application',
		/** `console` : bin/console, artisan, docker… */
		consoleCommand: (console: string) => `Commande ${console}`,
		requestMethod: 'Méthode HTTP',
		requestPath: 'Chemin de la requête',
		requestSendTitle: 'Entrée dans le chemin, ou Ctrl/⌘ + Entrée',
		requestHeadersPlaceholder: 'En-têtes, un par ligne (ex. Authorization: Bearer …)',
		requestHeaders: 'En-têtes',
		requestBodyPlaceholder: 'Corps JSON (ex. {"nom": "Gimli"})',
		requestBody: 'Corps de la requête',
		response: 'Réponse',
		responseHeaders: 'En-têtes de la réponse',
		bootPreparing: 'Préparation de l\'environnement…',
		previousExercise: (title: string) => `Exercice précédent : ${title}`,
		nextExercise: (title: string) => `Exercice suivant : ${title}`,
		chapterTitle: (number: number, title: string) => `Chapitre ${number} : ${title}`,
		chapterCrumb: (number: number, title: string) => `Ch. ${number} · ${title}`,
		position: (position: number, total: number) => `Exercice ${position} sur ${total} du chapitre`,
		neighbours: 'Exercices voisins',
		furtherTitle: 'Pour aller plus loin',
		furtherVersion: (framework: string, version: string) => `Les autres nouveautés de ${framework} ${version}`,
		furtherTrack: (track: string) => `Le parcours ${track}`,
		furtherPractice: 'Tous les exercices de la Pratique',
	},

	hints: {
		/** Le bouton des indices ; `cost` : l'XP qu'un indice de plus coûterait (0 : gratuit). */
		hint: (cost: number) => (cost <= 0 ? 'Un indice ?' : `Un indice ? (−${cost} XP)`),
		hintNumber: (index: number, total: number) => `Indice ${index}/${total}`,
		solutionSeen: 'Solution consultée.',
		solutionSeenNoXp: 'Solution consultée : cet exercice ne rapportera pas d\'XP.',
		solutionDone: 'Solution ✓',
		solutionTitle: 'La solution de référence.',
		solutionTitleXp: 'La solution de référence. La consulter ne rapporte pas l\'XP de cet exercice.',
		confirmTitle: 'Consulter la solution ?',
		confirmMessage: 'Cet exercice ne rapportera alors pas d\'XP, même réussi ensuite avec votre code.',
		confirm: 'Voir la solution',
		keepSearching: 'Continuer à chercher',
		unavailable: 'Solution indisponible',
		devOnly: 'Visible en développement uniquement',
	},

	results: {
		/** Lu par un lecteur d'écran avant le libellé de l'objectif. */
		state: { passed: 'Atteint : ', failed: 'Pas encore atteint : ', unknown: 'Non vérifié : ' },
		spokenSummary: (passed: number, total: number) =>
			passed === total ? `Tous les objectifs sont atteints (${total} sur ${total}).` : `${passed} objectif${passed > 1 ? 's' : ''} atteint${passed > 1 ? 's' : ''} sur ${total}.`,
	},

	mentor: {
		title: 'Le mentor',
		review: 'Revue de code',
		askReview: 'Demander une revue de code',
		reviewing: 'Le mentor relit votre code…',
		looking: 'Le mentor regarde…',
		reading: 'Il lit l\'erreur et votre code…',
		nothingToSay: 'Rien à redire : c\'est propre.',
		clue: 'Piste :',
		/** `fichier` : le lien vers le fichier, prêt. */
		lookAt: (fichier: string) => `Regardez du côté de ${fichier}.`,
		sessionExpired: 'Votre session a expiré : reconnectez-vous, puis rechargez la page.',
		accessEnded: 'Votre accès à ce parcours a pris fin : le mentor n\'est plus disponible pour cet exercice.',
		httpError: (status: number) => `réponse ${status}`,
	},

	success: {
		/** `title` : déjà échappé. */
		lessonLink: (title: string) => `📜 Fiche de cours du chapitre « ${title} » →`,
		alreadyDone: '✓ Vous avez déjà réussi cet exercice.',
		alreadyDoneLast: '✓ Vous avez déjà réussi cet exercice, le dernier du parcours.',
		nextExercise: (title: string) => `Exercice suivant : ${title} →`,
		continueWith: (track: string) => `Continuer avec « ${track} » →`,
		alreadyValidated: 'Exercice déjà validé.',
		noXpSolution: 'Sans XP : la solution a été consultée.',
		xpGain: (xp: number) => `+${xp} XP`,
		totalXp: (xp: number) => `${xp} XP au total`,
		/** `lien` enrobe le texte du lien vers la pull request. */
		pullRequest: (lien: (texte: string) => string) => `Envie de voir comment la fonctionnalité est faite ? ${lien('La pull request d\'origine')}.`,
		/** `parcours` : son titre, déjà en HTML ; `description` : déjà échappée. */
		practiceTrack: (parcours: string, description: string | null) =>
			`Cet exercice porte sur une seule fonctionnalité. Pour aller plus loin, le parcours ${parcours} reprend le framework de bout en bout${description ? ` : ${description}` : '.'}`,
		discover: (track: string) => `Découvrir « ${track} » →`,
		backToPractice: 'Retour à la Pratique',
		closedBeta: 'La suite du parcours est en bêta fermée. Vous avez un code d\'invitation ? Créez votre compte pour continuer. Sinon, laissez votre adresse : vous serez prévenu à l\'ouverture.',
		haveInvitation: 'J\'ai un code d\'invitation',
		joinWaitlist: 'Rejoindre la liste d\'attente',
		createAccountPitch: 'Créez un compte gratuit pour sauvegarder votre progression et continuer.',
		createAccount: 'Créer mon compte',
		/** `fini` : le parcours terminé ; `suivant` : le suivant, déjà en HTML. */
		lastThenNext: (fini: string, suivant: string, description: string | null) =>
			`C'était le dernier exercice de « ${fini} ». Et maintenant ? Continuez avec ${suivant}${description ? ` : ${description}` : '.'}`,
		start: (track: string) => `Commencer « ${track} » →`,
		backToTrack: 'Retour au parcours',
		last: (track: string) => `C'était le dernier exercice de « ${track} ».`,
		succeeded: 'Exercice réussi !',
		chapterDone: 'Vous avez terminé le chapitre.',
		shareSuccess: 'Partager votre réussite',
		saveFailed: (error: string) => `La progression n'a pas pu être enregistrée : ${error}`,
	},

	feedback: {
		kinds: {
			bug: 'Un bogue (les tests, l’aperçu, l’éditeur…)',
			unclear: 'L’énoncé n’est pas clair',
			'too-easy': 'Trop facile',
			'too-hard': 'Trop difficile',
			other: 'Autre chose',
		},
		title: 'Un avis sur cet exercice ?',
		intro: 'Chaque retour aide à améliorer la plateforme. Merci !',
		kind: 'Il s’agit de…',
		message: 'Votre message',
		placeholder: 'Ce qui a bloqué, ce qui manquait, ce qui a plu…',
		thanks: 'Merci, c’est noté !',
		failed: (message: string) => `Envoi impossible : ${message}`,
		sessionExpired: 'votre session a expiré, reconnectez-vous puis rechargez la page',
		tooMany: 'beaucoup de retours en peu de temps, réessayez dans une heure',
		httpError: (status: number) => `réponse ${status}`,
	},

	console: {
		/** L'origine d'un message venu de la page de l'aperçu. */
		browser: 'navigateur',
		noConsole: 'Ce runtime n\'a pas de console.',
		noOutput: '(aucune sortie)',
		exitCode: (code: number) => `code de sortie : ${code}`,
	},

	diff: {
		title: 'Avant / après',
		file: 'Fichier',
		before: 'Code de départ',
		after: 'Votre code',
		noChange: 'Aucun fichier n’a changé.',
	},

	folding: {
		show: { brief: 'Afficher les consignes', preview: 'Afficher l\'aperçu' },
		hide: { brief: 'Replier les consignes', preview: 'Replier l\'aperçu' },
		/** Lus par un lecteur d'écran sur la rail des consignes. */
		allPassed: 'tous les objectifs sont atteints',
		failing: (failed: number) => `${failed} objectif${failed > 1 ? 's' : ''} en échec`,
	},

	files: {
		resetTitle: 'Revenir au code de départ ?',
		resetMessage: 'Vos modifications seront remplacées par le code de départ. Vous pourrez annuler juste après.',
		reset: 'Réinitialiser',
		resetDone: 'Code de départ rétabli.',
		newFilePrompt: (allowed: string) => `Chemin du nouveau fichier (autorisés : ${allowed})`,
		/** Le nom proposé pour un nouveau fichier, à la place de l'étoile du motif (src/Entity/Nouveau.php). */
		newFileName: 'Nouveau',
		notEditableTitle: 'Fichier non modifiable',
		notEditable: (path: string, allowed: string) => `« ${path} » ne fait pas partie des fichiers modifiables de cet exercice (${allowed}).`,
	},

	progress: {
		sessionExpired: 'votre session a expiré : reconnectez-vous, puis rechargez la page (votre code reste dans l\'éditeur)',
		accessEnded: 'votre accès à ce parcours a pris fin : la progression déjà enregistrée est conservée, et vous la retrouverez avec un nouvel accès',
		saveFailed: (status: number) => `Sauvegarde impossible (${status})`,
	},

	requests: {
		emptyBody: '(corps vide)',
		error: 'Erreur',
	},

	database: {
		none: 'Aucune base SQLite dans le projet pour l\'instant.',
		noTables: 'Aucune table.',
		unreadable: (error: string) => `Base illisible : ${error}`,
		rows: (count: number) => (count > 1 ? `${count} lignes` : `${count} ligne`),
		firstRows: (shown: number, count: number) => `${shown} premières lignes sur ${count}`,
		bytes: (count: number) => `${count} octets`,
		/** Au survol d'une valeur qui n'est pas du texte (colonne chiffrée, BLOB). */
		bytesTitle: (count: number) => `${count} octets qui ne sont pas du texte (valeur chiffrée ou binaire), montrés en hexadécimal`,
	},

	share: {
		/** L'intitulé devant les boutons. */
		heading: (title: string) => `${title} :`,
		native: 'Partager…',
		copy: 'Copier le lien',
		copied: 'Lien copié.',
		on: 'Partager sur',
	},

	shortcuts: {
		title: 'Raccourcis clavier',
		/** `mod` : « Ctrl » ou « ⌘ ». */
		withEnter: (mod: string) => `${mod} + Entrée`,
		escape: 'Échap',
		run: 'Lancer les tests',
		save: 'Enregistrer le brouillon tout de suite',
		foldBrief: 'Replier ou afficher les consignes (écran large)',
		foldPreview: 'Replier ou afficher l\'aperçu (écran large)',
		tabTrap: 'Dans l\'éditeur : Tab passe au bouton suivant au lieu d\'indenter (et inversement)',
		commands: 'Dans l\'éditeur : toutes les commandes',
		closeHelp: 'Fermer cette aide',
	},

	dialogs: {
		ok: 'Compris',
	},

	editor: {
		readonlyTitle: (path: string) => `${path} (lecture seule)`,
		readonlyMessage: 'Ce fichier est fourni par l\'exercice et ne peut pas être modifié.',
	},

	completion: {
		/** Une variable de template, et le contrôleur qui la passe. */
		passedBy: (controller: string) => `passée par ${controller}`,
		php: {
			route: 'Attribut de route Symfony',
			action: 'Action de contrôleur complète (route + rendu Twig)',
			render: 'Rendu d\'un template Twig',
			id: 'Identifiant auto-incrémenté d\'une entité Doctrine',
			column: 'Propriété mappée sur une colonne',
		},
		laravel: {
			route: 'Route vers une méthode de contrôleur',
			action: 'Méthode de contrôleur complète (rendu d\'une vue Blade)',
			view: 'Rendu d\'une vue Blade',
			foreach: 'Boucle Blade',
			if: 'Condition Blade',
			extends: 'Vue Blade héritant d\'un layout',
			migration: 'Création de table dans une migration',
		},
		dockerfile: {
			from: 'Image de base',
			workdir: 'Dossier de travail des instructions suivantes',
			copy: 'Copier depuis le contexte de build',
			copyFrom: 'Copier depuis une autre image ou une autre étape',
			runApt: 'Installer des paquets et une extension PHP, en une seule couche',
			runApk: 'Installer une extension sur Alpine, puis retirer les outils de compilation',
			composer: 'Installer les dépendances avant de copier le code (cache des couches)',
			env: 'Variable d\'environnement de l\'image',
			arg: 'Argument de construction',
			expose: 'Documenter le port du service',
			user: 'Utilisateur des instructions suivantes et du processus',
			healthcheck: 'Contrôle de santé du conteneur',
			entrypoint: 'Script d\'entrée et commande par défaut',
			cmd: 'Commande par défaut, en forme exec',
			docroot: 'Changer le DocumentRoot d\'Apache',
		},
		compose: {
			services: 'Squelette de compose.yaml',
			service: 'Service construit depuis le Dockerfile',
			serviceImage: 'Service à partir d\'une image',
			db: 'Service PostgreSQL avec contrôle de santé',
			volumes: 'Montage du code dans le conteneur',
			depends: 'Dépendance conditionnée au contrôle de santé',
			healthcheck: 'Contrôle de santé du service',
		},
		twig: {
			for: 'Boucle Twig',
			if: 'Condition Twig',
			block: 'Bloc Twig',
			extends: 'Héritage de template',
			path: 'URL d\'une route nommée',
			asset: 'URL d\'un fichier de public/',
		},
	},

	preview: {
		notResponding: (origin: string) => `L'aperçu ne répond pas (${origin}) : rechargez la page.`,
		relayError: (message: string) => `Relais de l'aperçu : ${message}`,
		requestRefused: 'Requête d\'aperçu refusée.',
	},

	sandbox: {
		reserved: 'Page réservée à l\'aperçu des exercices.',
		serviceWorkersRefused: 'Ce navigateur refuse les Service Workers ici (navigation privée ?) : l\'aperçu en a besoin.',
		serviceWorkersBlocked: 'Ce navigateur bloque les Service Workers : l\'aperçu en a besoin.',
		activationTimeout: (seconds: number) => `Le Service Worker de l'aperçu ne s'est pas activé en ${seconds} s : rechargez la page.`,
		rejected: 'Service Worker de l\'aperçu rejeté.',
	},

	runtime: {
		unknown: (id: string, known: string[]) => `Runtime « ${id} » inconnu de ce playground (connus : ${known.join(', ') || 'aucun'}).`,
		downloadFailed: (url: string, status: number) => `Téléchargement impossible : ${url} (${status})`,
		downloading: (megabytes: number) => `Téléchargement de l'environnement (${megabytes.toFixed(1)} Mo)`,
		phpBinaryMissing: (file: string, status: number) => `Binaire PHP introuvable : ${file} (${status})`,
		notBooted: 'Environnement non démarré.',
		unknownPhpFramework: (id: string, console: string, known: string[]) => `Framework PHP « ${id} » inconnu de ce playground (connus : ${known.join(', ')}) : sa console « ${console} » n'a pas de script (voir src/runtime/php-frameworks.ts).`,
		wrongRuntime: (runtime: string) => `Un environnement « ${runtime} » ne se joue pas avec PHP (voir src/runtime/registry.ts).`,
		booting: (version: string) => `Démarrage de PHP ${version}`,
		badUrl: '400 Bad Request : URL mal encodée.',
		noReport: 'PHPUnit s\'est arrêté sans rapport.',
		noOwnTest: 'Aucun de vos tests ne s\'est exécuté : écrivez au moins une méthode test…() dans votre classe de test.',
		skipped: '(incomplet ou ignoré)',
		mutants: 'Mutants (versions cassées de l\'application) :',
	},

	/** Le bac à sable : sans tests, « Exécuter » recharge l'aperçu ; le code se partage par l'adresse. */
	scratch: {
		/** Le bouton de la barre : `masquable` enrobe ce qui disparaît sur un écran étroit. */
		run: (masquable: (texte: string) => string) => `Exécuter${masquable('')}`,
		/** `mod` : « Ctrl » ou « ⌘ ». */
		runTitle: (mod: string) => `Recharger l'aperçu avec votre code (${mod} + Entrée)`,
		ran: 'Aperçu rechargé',
		shareTitle: 'Partager votre code',
		sharedStatus: 'Code partagé : lisez-le avant de l\'exécuter',
		sharedTitle: 'Ce lien contient du code écrit par quelqu\'un d\'autre.',
		sharedDetail: 'Lisez-le dans l\'éditeur avant de l\'exécuter. Il tourne isolé de ce site, mais la page qu\'il affiche peut imiter n\'importe quoi : n\'y saisissez jamais un mot de passe.',
		sharedRun: 'Exécuter ce code',
		/** Le bouton « Réinitialiser » du bac à sable, et le projet vierge ouvert depuis /bac-a-sable. */
		newProject: {
			resetTitle: 'Repartir d\'un projet vierge ?',
			resetMessage: 'Votre code sera remplacé par le projet de départ. Vous pourrez annuler juste après. Un lien déjà partagé garde son code.',
			reset: 'Nouveau projet',
			buttonTitle: 'Repartir d\'un projet vierge',
			resetDone: 'Nouveau projet.',
		},
		newProjectDone: 'Nouveau projet vierge.',
		resumeDraft: 'Reprendre mon brouillon',
	},

	site: {
		expandAll: 'Tout ouvrir',
		collapseAll: 'Tout fermer',
	},
};
