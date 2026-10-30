/**
 * The playground's texts shown to the learner or visitor, in English.
 *
 * Same keys, same parameters as `fr` (the reference catalogue): typed `Messages`, so tsc checks it is complete.
 */
import type { Messages } from './index.ts';

export const en: Messages = {
	common: {
		xp: (xp: number) => `${xp} XP`,
		close: 'Close',
		cancel: 'Cancel',
		send: 'Send',
		sending: 'Sending…',
	},

	boot: {
		firstLoad: 'First load of this environment: it downloads once (about twenty seconds), then the browser keeps it and the next exercises open much faster.',
		retry: 'Retry',
		readBrief: 'Read the brief',
		backHome: 'Back to home',
		sessionExpired: 'Your session has expired.',
		sessionExpiredDetail: 'Log in again: your saved code is waiting for you.',
		relogin: 'Log in again',
		forbidden: 'You no longer have access to this exercise.',
		forbiddenDetail: 'Your access to the track may have ended.',
		notFound: 'This exercise could not be found.',
		notFoundDetail: 'It may have been renamed or removed from the track.',
		loadFailed: 'The exercise could not be loaded.',
		offline: 'The connection seems to be down.',
		serverError: (status: number) => `The server is not responding properly right now (error ${status}).`,
		/** The runtime did not start (its name: PHP, the Nuxt simulator…). */
		runtimeFailed: (runtime: string, message: string) => `Could not start ${runtime}: ${message}`,
		startFailed: 'Could not start',
		/** Setup failed somewhere other than the runtime start. */
		openFailed: (message: string) => `The exercise could not be opened: ${message}`,
		/** A setup command (e.g. “bin/console doctrine:schema:update --force”). */
		preparing: (command: string) => `Preparing: ${command}`,
	},

	playground: {
		previewFrozen: 'The preview page is not responding: infinite loop in its code?',
		previewFrozenConsole: 'The preview page has not responded for several seconds: infinite loop in its code (onMounted, watch, event handler…), or a dialog (alert, confirm) left open? The preview was restarted without reloading this page. Fix the loop, then reload the preview (⟳).',
		previewRecovered: 'Preview restarted. Fix the loop, then reload it (⟳).',
		previewRestartFailed: 'The preview could not be restarted: reload it (⟳) to try again.',
		previewProblem: 'The preview page reported a problem',
		/** Read by a screen reader on the collapsed preview rail, after its name. */
		previewProblemSpoken: 'the preview page reported a problem',
		setupFailedSpoken: 'a setup command failed',
		restarting: 'The code is not responding (infinite loop?): restarting…',
		restartFailed: (error: string) => `Could not restart: ${error}. Reload the page.`,
		restarted: 'Restarted. Fix the loop, then run again.',
		draftSaved: 'Draft saved',
		draftNotSaved: (message: string) => `Draft not saved: ${message}`,
		testsRunning: 'Running tests…',
		testsDone: (passed: number, total: number, ms: number) => `${passed}/${total} objectives · tests in ${ms} ms`,
		testsError: 'Error while running tests',
		/** `mod`: “Ctrl” or “⌘”. */
		runTitle: (mod: string) => `Run tests (${mod} + Enter)`,
		ready: (seconds: number) => `Ready in ${seconds.toFixed(1)} s`,
	},

	layout: {
		doneChipTitle: 'Exercise completed',
		doneChip: 'Completed',
		paneSwitch: 'Visible pane',
		brief: 'Brief',
		code: 'Code',
		preview: 'Preview',
		starting: 'Starting…',
		userTitle: 'Logged in',
		guestTitle: 'Progress saved in this browser',
		guest: 'Guest',
		login: 'Log in',
		shareTitle: 'Share this exercise',
		share: 'Share',
		feedbackTitle: 'Report a problem or give feedback on this exercise',
		feedback: 'Feedback?',
		solution: 'Solution',
		resetTitle: 'Go back to the starter code',
		reset: 'Reset',
		shortcuts: 'Keyboard shortcuts',
		/** The tests button: `masquable` wraps what disappears on a narrow screen. */
		run: (masquable: (texte: string) => string) => `${masquable('Run ')}tests`,
		smallScreen: 'This exercise works best on a wide screen: here, one pane at a time.',
		inAppBrowser: 'The app\'s built-in browser starts the exercise slowly: open this page in your browser (⋯ menu › Open in browser).',
		versionTitle: 'The other exercises for this version',
		objectives: 'Objectives',
		docs: 'Helpful docs',
		/** `runner`: PHPUnit, Vitest… */
		testOutput: (runner: string) => `${runner} output`,
		explainTitle: 'Ask the mentor what this error means',
		explainError: 'Explain the error',
		explain: 'Explain',
		treeTitle: 'Show or hide the project files',
		tree: 'Project files',
		project: 'Project',
		newFile: 'New file',
		console: 'Console',
		requests: 'Requests',
		database: 'Database',
		databaseNote: 'The preview\'s database, read without going through the application.',
		databaseRefresh: 'Read the database again',
		commandFailed: 'A command failed',
		sandboxTitle: 'The application runs in an isolated sandbox',
		sandbox: 'preview',
		previewPath: 'Preview path',
		pageTitleHint: 'Tab title: the page <title>',
		reloadTitle: 'Reload',
		reload: 'Reload the preview',
		previewFrozen: 'The page stopped responding: infinite loop in its code, or a dialog left open? The preview was restarted without reloading it. Fix the loop, then reload (⟳).',
		previewFrame: 'Application preview',
		/** `console`: bin/console, artisan, docker… */
		consoleCommand: (console: string) => `${console} command`,
		requestMethod: 'HTTP method',
		requestPath: 'Request path',
		requestSendTitle: 'Press Enter in the path field, or Ctrl/⌘ + Enter',
		requestHeadersPlaceholder: 'Headers, one per line (e.g. Authorization: Bearer …)',
		requestHeaders: 'Headers',
		requestBodyPlaceholder: 'JSON body (e.g. {"nom": "Gimli"})',
		requestBody: 'Request body',
		response: 'Response',
		responseHeaders: 'Response headers',
		bootPreparing: 'Preparing the environment…',
		previousExercise: (title: string) => `Previous exercise: ${title}`,
		nextExercise: (title: string) => `Next exercise: ${title}`,
		chapterTitle: (number: number, title: string) => `Chapter ${number}: ${title}`,
		chapterCrumb: (number: number, title: string) => `Ch. ${number} · ${title}`,
		position: (position: number, total: number) => `Exercise ${position} of ${total} in the chapter`,
		neighbours: 'Nearby exercises',
		furtherTitle: 'Going further',
		furtherVersion: (framework: string, version: string) => `The other new features in ${framework} ${version}`,
		furtherTrack: (track: string) => `The ${track} track`,
		furtherPractice: 'All Practice exercises',
	},

	hints: {
		/** The hints button; `cost`: the XP one more hint would cost (0: free). */
		hint: (cost: number) => (cost <= 0 ? 'Need a hint?' : `Need a hint? (−${cost} XP)`),
		hintNumber: (index: number, total: number) => `Hint ${index}/${total}`,
		solutionSeen: 'Solution viewed.',
		solutionSeenNoXp: 'Solution viewed: this exercise will not earn XP.',
		solutionDone: 'Solution ✓',
		solutionTitle: 'The reference solution.',
		solutionTitleXp: 'The reference solution. Viewing it forfeits this exercise\'s XP.',
		confirmTitle: 'View the solution?',
		confirmMessage: 'This exercise will then not earn XP, even if you later pass it with your own code.',
		confirm: 'Show the solution',
		keepSearching: 'Keep trying',
		unavailable: 'Solution unavailable',
		devOnly: 'Visible in development only',
	},

	results: {
		/** Read by a screen reader before the objective's label. */
		state: { passed: 'Met: ', failed: 'Not met yet: ', unknown: 'Not checked: ' },
		spokenSummary: (passed: number, total: number) =>
			passed === total ? `All objectives met (${total} of ${total}).` : `${passed} of ${total} objective${total > 1 ? 's' : ''} met.`,
	},

	mentor: {
		title: 'The mentor',
		review: 'Code review',
		askReview: 'Ask for a code review',
		reviewing: 'The mentor is reviewing your code…',
		looking: 'The mentor is looking…',
		reading: 'Reading the error and your code…',
		nothingToSay: 'Nothing to add: it\'s clean.',
		clue: 'Clue:',
		/** `fichier`: the link to the file, ready. */
		lookAt: (fichier: string) => `Take a look at ${fichier}.`,
		sessionExpired: 'Your session has expired: log in again, then reload the page.',
		accessEnded: 'Your access to this track has ended: the mentor is no longer available for this exercise.',
		httpError: (status: number) => `response ${status}`,
	},

	success: {
		/** `title`: already escaped. */
		lessonLink: (title: string) => `📜 Lesson notes for the “${title}” chapter →`,
		alreadyDone: '✓ You have already completed this exercise.',
		alreadyDoneLast: '✓ You have already completed this exercise, the last one in the track.',
		nextExercise: (title: string) => `Next exercise: ${title} →`,
		continueWith: (track: string) => `Continue with “${track}” →`,
		alreadyValidated: 'Exercise already completed.',
		noXpSolution: 'No XP: the solution was viewed.',
		xpGain: (xp: number) => `+${xp} XP`,
		totalXp: (xp: number) => `${xp} XP total`,
		/** `lien` wraps the text of the link to the pull request. */
		pullRequest: (lien: (texte: string) => string) => `Curious how the feature was built? ${lien('See the original pull request')}.`,
		/** `parcours`: its title, already HTML; `description`: already escaped. */
		practiceTrack: (parcours: string, description: string | null) =>
			`This exercise covers a single feature. To go further, the ${parcours} track covers the framework end to end${description ? `: ${description}` : '.'}`,
		discover: (track: string) => `Discover “${track}” →`,
		backToPractice: 'Back to Practice',
		closedBeta: 'The rest of the track is in closed beta. Have an invitation code? Create your account to continue. Otherwise, leave your email: we\'ll let you know when it opens.',
		haveInvitation: 'I have an invitation code',
		joinWaitlist: 'Join the waitlist',
		createAccountPitch: 'Create a free account to save your progress and continue.',
		createAccount: 'Create my account',
		/** `fini`: the finished track; `suivant`: the next one, already HTML. */
		lastThenNext: (fini: string, suivant: string, description: string | null) =>
			`That was the last exercise in “${fini}”. What's next? Continue with ${suivant}${description ? `: ${description}` : '.'}`,
		start: (track: string) => `Start “${track}” →`,
		backToTrack: 'Back to the track',
		last: (track: string) => `That was the last exercise in “${track}”.`,
		succeeded: 'Exercise completed!',
		chapterDone: 'You have finished the chapter.',
		shareSuccess: 'Share your success',
		saveFailed: (error: string) => `Your progress could not be saved: ${error}`,
	},

	feedback: {
		kinds: {
			bug: 'A bug (tests, preview, editor…)',
			unclear: 'The brief is unclear',
			'too-easy': 'Too easy',
			'too-hard': 'Too hard',
			other: 'Something else',
		},
		title: 'Feedback on this exercise?',
		intro: 'Every piece of feedback helps improve the platform. Thank you!',
		kind: 'It\'s about…',
		message: 'Your message',
		placeholder: 'What got you stuck, what was missing, what you liked…',
		thanks: 'Thanks, noted!',
		failed: (message: string) => `Could not send: ${message}`,
		sessionExpired: 'your session has expired, log in again then reload the page',
		tooMany: 'a lot of feedback in a short time, try again in an hour',
		httpError: (status: number) => `response ${status}`,
	},

	console: {
		/** The origin of a message coming from the preview page. */
		browser: 'browser',
		noConsole: 'This runtime has no console.',
		noOutput: '(no output)',
		exitCode: (code: number) => `exit code: ${code}`,
	},

	diff: {
		title: 'Before / after',
		file: 'File',
		before: 'Starter code',
		after: 'Your code',
		noChange: 'No file has changed.',
	},

	folding: {
		show: { brief: 'Show the brief', preview: 'Show the preview' },
		hide: { brief: 'Collapse the brief', preview: 'Collapse the preview' },
		/** Read by a screen reader on the brief rail. */
		allPassed: 'all objectives met',
		failing: (failed: number) => `${failed} objective${failed > 1 ? 's' : ''} not met`,
	},

	files: {
		resetTitle: 'Go back to the starter code?',
		resetMessage: 'Your changes will be replaced by the starter code. You can undo right after.',
		reset: 'Reset',
		resetDone: 'Starter code restored.',
		newFilePrompt: (allowed: string) => `Path of the new file (allowed: ${allowed})`,
		/** The name suggested for a new file, in place of the pattern's star (src/Entity/New.php). */
		newFileName: 'New',
		notEditableTitle: 'File not editable',
		notEditable: (path: string, allowed: string) => `“${path}” is not one of this exercise's editable files (${allowed}).`,
	},

	progress: {
		sessionExpired: 'your session has expired: log in again, then reload the page (your code stays in the editor)',
		accessEnded: 'your access to this track has ended: the progress already saved is kept, and you will get it back with new access',
		saveFailed: (status: number) => `Could not save (${status})`,
	},

	requests: {
		emptyBody: '(empty body)',
		error: 'Error',
	},

	database: {
		none: 'No SQLite database in the project yet.',
		noTables: 'No tables.',
		unreadable: (error: string) => `Unreadable database: ${error}`,
		rows: (count: number) => (count === 1 ? '1 row' : `${count} rows`),
		firstRows: (shown: number, count: number) => `first ${shown} rows of ${count}`,
		bytes: (count: number) => `${count} bytes`,
		/** On hover over a value that is not text (encrypted column, BLOB). */
		bytesTitle: (count: number) => `${count} bytes that are not text (an encrypted or binary value), shown in hexadecimal`,
	},

	share: {
		/** The label before the buttons. */
		heading: (title: string) => `${title}:`,
		native: 'Share…',
		copy: 'Copy link',
		copied: 'Link copied.',
		on: 'Share on',
	},

	shortcuts: {
		title: 'Keyboard shortcuts',
		/** `mod`: “Ctrl” or “⌘”. */
		withEnter: (mod: string) => `${mod} + Enter`,
		escape: 'Esc',
		run: 'Run tests',
		save: 'Save the draft right now',
		foldBrief: 'Collapse or show the brief (wide screen)',
		foldPreview: 'Collapse or show the preview (wide screen)',
		tabTrap: 'In the editor: Tab moves to the next button instead of indenting (and back)',
		commands: 'In the editor: all commands',
		closeHelp: 'Close this help',
	},

	dialogs: {
		ok: 'Got it',
	},

	editor: {
		readonlyTitle: (path: string) => `${path} (read-only)`,
		readonlyMessage: 'This file is provided by the exercise and cannot be edited.',
	},

	completion: {
		/** A template variable, and the controller that passes it. */
		passedBy: (controller: string) => `passed by ${controller}`,
		php: {
			route: 'Symfony route attribute',
			action: 'Full controller action (route + Twig render)',
			render: 'Render a Twig template',
			id: 'Auto-incremented Doctrine entity ID',
			column: 'Property mapped to a column',
		},
		laravel: {
			route: 'Route to a controller method',
			action: 'Full controller method (renders a Blade view)',
			view: 'Render a Blade view',
			foreach: 'Blade loop',
			if: 'Blade condition',
			extends: 'Blade view extending a layout',
			migration: 'Create a table in a migration',
		},
		dockerfile: {
			from: 'Base image',
			workdir: 'Working directory for the following instructions',
			copy: 'Copy from the build context',
			copyFrom: 'Copy from another image or stage',
			runApt: 'Install packages and a PHP extension, in a single layer',
			runApk: 'Install an extension on Alpine, then remove the build tools',
			composer: 'Install dependencies before copying the code (layer cache)',
			env: 'Image environment variable',
			arg: 'Build argument',
			expose: 'Document the service port',
			user: 'User for the following instructions and the process',
			healthcheck: 'Container health check',
			entrypoint: 'Entrypoint script and default command',
			cmd: 'Default command, in exec form',
			docroot: 'Change Apache\'s DocumentRoot',
		},
		compose: {
			services: 'compose.yaml skeleton',
			service: 'Service built from the Dockerfile',
			serviceImage: 'Service from an image',
			db: 'PostgreSQL service with health check',
			volumes: 'Mount the code into the container',
			depends: 'Dependency gated on the health check',
			healthcheck: 'Service health check',
		},
		twig: {
			for: 'Twig loop',
			if: 'Twig condition',
			block: 'Twig block',
			extends: 'Template inheritance',
			path: 'URL of a named route',
			asset: 'URL of a file in public/',
		},
	},

	preview: {
		notResponding: (origin: string) => `The preview is not responding (${origin}): reload the page.`,
		relayError: (message: string) => `Preview relay: ${message}`,
		requestRefused: 'Preview request refused.',
	},

	sandbox: {
		reserved: 'This page is reserved for exercise previews.',
		serviceWorkersRefused: 'This browser refuses Service Workers here (private browsing?): the preview needs them.',
		serviceWorkersBlocked: 'This browser blocks Service Workers: the preview needs them.',
		activationTimeout: (seconds: number) => `The preview's Service Worker did not activate within ${seconds} s: reload the page.`,
		rejected: 'Preview Service Worker rejected.',
	},

	runtime: {
		unknown: (id: string, known: string[]) => `Runtime “${id}” is unknown to this playground (known: ${known.join(', ') || 'none'}).`,
		downloadFailed: (url: string, status: number) => `Download failed: ${url} (${status})`,
		downloading: (megabytes: number) => `Downloading the environment (${megabytes.toFixed(1)} MB)`,
		phpBinaryMissing: (file: string, status: number) => `PHP binary not found: ${file} (${status})`,
		notBooted: 'Environment not started.',
		unknownPhpFramework: (id: string, console: string, known: string[]) => `PHP framework “${id}” is unknown to this playground (known: ${known.join(', ')}): its console “${console}” has no script (see src/runtime/php-frameworks.ts).`,
		wrongRuntime: (runtime: string) => `A “${runtime}” environment does not run with PHP (see src/runtime/registry.ts).`,
		booting: (version: string) => `Starting PHP ${version}`,
		badUrl: '400 Bad Request: malformed URL encoding.',
		noReport: 'PHPUnit stopped without a report.',
		noOwnTest: 'None of your tests ran: write at least one test…() method in your test class.',
		skipped: '(incomplete or skipped)',
		mutants: 'Mutants (broken versions of the application):',
	},

	scratch: {
		run: (masquable: (texte: string) => string) => `Run${masquable('')}`,
		runTitle: (mod: string) => `Reload the preview with your code (${mod} + Enter)`,
		ran: 'Preview reloaded',
		shareTitle: 'Share your code',
		sharedStatus: 'Shared code: read it before running it',
		sharedTitle: 'This link contains code written by someone else.',
		sharedDetail: 'Read it in the editor before running it. It runs isolated from this site, but the page it shows can imitate anything: never type a password into it.',
		sharedRun: 'Run this code',
		newProject: {
			resetTitle: 'Start from a blank project?',
			resetMessage: 'Your code will be replaced by the starter project. You can undo right after. A link you already shared keeps its code.',
			reset: 'New project',
			buttonTitle: 'Start from a blank project',
			resetDone: 'New project.',
		},
		newProjectDone: 'New blank project.',
		resumeDraft: 'Resume my draft',
	},

	packages: {
		button: 'Packages',
		buttonTitle: 'Add Composer packages to the project',
		title: 'Composer packages',
		intro: 'Tick a package: it is added to the project with its dependencies, and require \'vendor/autoload.php\' loads it, just like after a composer require. The shared link keeps your packages.',
		adding: (name: string) => `Adding ${name}…`,
		added: (name: string) => `${name} added.`,
		removing: (name: string) => `Removing ${name}…`,
		removed: (name: string) => `${name} removed.`,
		failed: (name: string, reason: string) => `${name} could not be added: ${reason}`,
		downloadFailed: (name: string, status: number) => `download of ${name} refused (HTTP ${status})`,
		request: 'Missing a package? Let us know',
		unavailable: 'The package catalogue is unavailable right now.',
		kilobytes: (n: string) => `${n} KB`,
		megabytes: (n: string) => `${n} MB`,
	},

	site: {
		expandAll: 'Expand all',
		collapseAll: 'Collapse all',
	},
};
