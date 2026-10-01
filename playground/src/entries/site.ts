/**
 * Progression jouée en invité (localStorage) : une fois connecté, on la remonte au compte,
 * puis on l'efface du navigateur. La Pratique n'a pas de parcours : sa clé est `formation:null/<id>`.
 */
const GUEST_PREFIX = 'formation:';
const PRACTICE = 'null';

async function importGuestProgress(url: string) {
	const items: unknown[] = [];
	const keys: string[] = [];
	try {
		for (let i = 0; i < localStorage.length; i++) {
			const key = localStorage.key(i)!;
			const match = key.startsWith(GUEST_PREFIX) ? key.slice(GUEST_PREFIX.length).match(/^([^/]+)\/([^/]+)$/) : null;
			if (!match) continue;
			const saved = JSON.parse(localStorage.getItem(key) ?? 'null') as { files?: Record<string, string>; hintsUsed?: number; completed?: boolean } | null;
			if (!saved) continue;
			items.push({ trackId: match[1] === PRACTICE ? null : match[1], exerciseId: match[2], files: saved.files ?? {}, hintsUsed: saved.hintsUsed ?? 0, completed: !!saved.completed });
			keys.push(key);
		}
	} catch {
		return; // stockage indisponible
	}
	if (!items.length) return;

	const response = await fetch(url, {
		method: 'POST',
		headers: { 'content-type': 'application/json', accept: 'application/json' },
		body: JSON.stringify(items),
		credentials: 'same-origin',
	});
	if (!response.ok) return;
	for (const key of keys) localStorage.removeItem(key);
	const { imported } = (await response.json()) as { imported: number };
	// La page affiche l'XP du compte : on la recharge pour refléter la progression reprise.
	if (imported > 0) location.reload();
}

const importUrl = document.body.dataset.importUrl;
if (importUrl) void importGuestProgress(importUrl);

/** Atelier des auteurs : les brouillons de post LinkedIn d'un parcours ou d'un exercice de Pratique. */
const post = document.querySelector<HTMLElement>('[data-post]');
// Chargé à la demande : ce code ne sert qu'aux auteurs, sur une seule page de l'atelier.
if (post) void import('../app/post').then(({ mountPostStudio }) => mountPostStudio(post, JSON.parse(post.dataset.post!)));

/** Atelier des auteurs : création d'un exercice depuis la liste des parcours. */
for (const form of document.querySelectorAll<HTMLFormElement>('form[data-nouveau]')) {
	form.addEventListener('submit', async (event) => {
		event.preventDefault();
		const erreur = form.querySelector<HTMLElement>('.erreur')!;
		const bouton = form.querySelector('button')!;
		erreur.textContent = '';
		bouton.disabled = true;
		try {
			const données = Object.fromEntries(new FormData(form));
			const response = await fetch(form.dataset.nouveau!, {
				method: 'POST',
				headers: { 'content-type': 'application/json', accept: 'application/json' },
				credentials: 'same-origin',
				body: JSON.stringify(données),
			});
			const corps = (await response.json().catch(() => ({}))) as { url?: string; erreur?: string; detail?: string };
			if (response.ok && corps.url) {
				location.href = corps.url;
				return;
			}
			erreur.textContent = corps.erreur ?? corps.detail ?? `Création refusée (${response.status}).`;
		} finally {
			bouton.disabled = false;
		}
	});
}

// Les animations d'accueil (barre de lecture, apparition des cartes, démonstration, onglets) sont du décor :
// elles vivent dans les thèmes (assets/theme.js), plus dans le moteur (#231).

// Menu du compte (<details>) : se ferme au clic ailleurs, à la touche Échap et en suivant un de ses liens.
for (const menu of document.querySelectorAll<HTMLDetailsElement>('[data-account-menu]')) {
	document.addEventListener('click', (event) => {
		if (menu.open && !menu.contains(event.target as Node)) menu.open = false;
	});
	menu.addEventListener('keydown', (event) => {
		if (event.key === 'Escape' && menu.open) {
			menu.open = false;
			menu.querySelector('summary')?.focus();
		}
	});
}

/**
 * Carte d'un parcours : les chapitres sont des <details>. Le sommaire ouvre le chapitre visé,
 * et « Tout ouvrir / Tout fermer » n'apparaît qu'avec JavaScript (sans lui, chaque chapitre s'ouvre à la main).
 */
/**
 * Panneau latéral repliable (filtres de la Pratique, sommaire de l'atelier) : déplié sur un écran large,
 * replié sur un téléphone. Sans ce script il reste déplié, comme le dit son attribut `open`.
 */
const ECRAN_ETROIT = matchMedia('(max-width: 900px)');
for (const panneau of document.querySelectorAll<HTMLDetailsElement>('[data-fold]')) {
	const suivre = () => {
		panneau.open = !ECRAN_ETROIT.matches;
	};
	suivre();
	ECRAN_ETROIT.addEventListener('change', suivre);
}

const CHAPTER_DETAILS = '.tr-chapter, .st-chapter'; // la carte d'un parcours, et la même liste dans l'atelier
const chaptersToggle = document.querySelector<HTMLButtonElement>('[data-chapters-toggle]');
if (chaptersToggle) {
	const chapters = [...document.querySelectorAll<HTMLDetailsElement>(CHAPTER_DETAILS)];
	const label = () => {
		chaptersToggle.textContent = chapters.every((chapter) => chapter.open) ? 'Tout fermer' : 'Tout ouvrir';
	};
	chaptersToggle.hidden = false;
	label();
	chaptersToggle.addEventListener('click', () => {
		const open = !chapters.every((chapter) => chapter.open);
		for (const chapter of chapters) chapter.open = open;
		label();
	});
	for (const chapter of chapters) chapter.addEventListener('toggle', label);
	for (const link of document.querySelectorAll<HTMLAnchorElement>('[data-open-chapter]')) {
		link.addEventListener('click', () => {
			const details = document.getElementById(link.dataset.openChapter!)?.querySelector<HTMLDetailsElement>(CHAPTER_DETAILS);
			if (details) details.open = true;
		});
	}
	// Arrivée par une ancre (#chapitre-3), ou ancre changée ensuite : le chapitre s'ouvre.
	const openFromHash = () => {
		const target = location.hash ? document.getElementById(location.hash.slice(1))?.querySelector<HTMLDetailsElement>(CHAPTER_DETAILS) : null;
		if (target) target.open = true;
	};
	openFromHash();
	window.addEventListener('hashchange', openFromHash);
}

/**
 * Filtres d'une liste (Pratique, atelier des auteurs) : un formulaire GET, qui marche sans JavaScript grâce
 * à son bouton d'envoi. Avec JavaScript, le bouton disparaît, chaque case envoie le formulaire elle-même,
 * la recherche attend qu'on ait fini de taper, puis reprend le curseur au rechargement.
 */
function filtresEnDirect(form: HTMLFormElement, cleFocus: string, parDefaut: Record<string, string> = {}) {
	const bouton = form.querySelector<HTMLButtonElement>('button[type="submit"]');
	const recherche = form.querySelector<HTMLInputElement>('input[type="search"]');
	if (bouton) bouton.hidden = true;

	// Ce qui vaut le défaut n'a rien à faire dans l'URL : on le débranche juste avant l'envoi.
	form.addEventListener('submit', () => {
		for (const champ of form.querySelectorAll<HTMLInputElement>('input[name]')) {
			if (champ.value === '' || champ.value === parDefaut[champ.name]) champ.disabled = true;
		}
	});

	form.addEventListener('change', (event) => {
		if (event.target === recherche) return; // la recherche a son propre rythme
		form.requestSubmit();
	});

	if (!recherche) return;
	let minuteur = 0;
	recherche.addEventListener('input', () => {
		clearTimeout(minuteur);
		minuteur = window.setTimeout(() => {
			try {
				sessionStorage.setItem(cleFocus, '1');
			} catch {
				// stockage indisponible : on perdra le curseur, sans plus
			}
			form.requestSubmit();
		}, 450);
	});

	// La page vient d'être rechargée par la recherche : on rend le curseur là où il était.
	try {
		if (sessionStorage.getItem(cleFocus)) {
			sessionStorage.removeItem(cleFocus);
			recherche.focus();
			recherche.setSelectionRange(recherche.value.length, recherche.value.length);
		}
	} catch {
		// stockage indisponible
	}
}

const filtresPratique = document.querySelector<HTMLFormElement>('[data-practice-filters]');
if (filtresPratique) filtresEnDirect(filtresPratique, 'pratique:recherche', { tri: 'recent' });

const filtresAtelier = document.querySelector<HTMLFormElement>('[data-studio-filters]');
if (filtresAtelier) filtresEnDirect(filtresAtelier, 'atelier:recherche');
