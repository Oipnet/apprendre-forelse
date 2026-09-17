import '../site.css';

/**
 * Progression jouée en invité (localStorage, exercices `free`) : une fois connecté,
 * on la remonte au compte, puis on l'efface du navigateur.
 */
const GUEST_PREFIX = 'formation:';

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
			items.push({ trackId: match[1], exerciseId: match[2], files: saved.files ?? {}, hintsUsed: saved.hintsUsed ?? 0, completed: !!saved.completed });
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

const animationRefusée = matchMedia('(prefers-reduced-motion: reduce)').matches;

/**
 * Avancée de lecture sous l'en-tête. Sans ce script la barre reste à zéro (largeur nulle,
 * donc invisible) : rien à masquer côté CSS.
 */
const barre = document.querySelector<HTMLElement>('[data-progress]');
if (barre) {
	let enAttente = false;
	const suivre = () => {
		if (enAttente) return;
		enAttente = true;
		requestAnimationFrame(() => {
			enAttente = false;
			const page = document.documentElement;
			const parcouru = page.scrollHeight - page.clientHeight;
			barre.style.transform = `scaleX(${parcouru > 0 ? Math.min(1, page.scrollTop / parcouru) : 0})`;
		});
	};
	addEventListener('scroll', suivre, { passive: true });
	addEventListener('resize', suivre, { passive: true });
	suivre();
}

/**
 * Apparition des cartes au défilement. L'attribut sur <html> déclenche la règle CSS qui les
 * masque : on ne le pose que si on est capable de les révéler ensuite.
 */
const révéler =
	animationRefusée || !('IntersectionObserver' in window)
		? null
		: new IntersectionObserver(
				(entrées, observateur) => {
					for (const entrée of entrées) {
						if (!entrée.isIntersecting) continue;
						entrée.target.classList.add('vu');
						observateur.unobserve(entrée.target);
					}
				},
				{ rootMargin: '0px 0px -12% 0px', threshold: 0.08 },
			);

if (révéler) {
	document.documentElement.dataset.revealActif = '';
	// Les cartes d'un onglet masqué ne croisent pas encore le champ de vision : elles restent
	// observées et se révéleront quand leur panneau s'affichera.
	for (const carte of document.querySelectorAll('[data-reveal]')) révéler.observe(carte);
}

/**
 * L'exercice de démonstration se rejoue tout seul : le dernier objectif bascule, le score,
 * l'XP et le prix suivent. Décoratif — on s'abstient si l'animation est refusée.
 */
const démo = document.querySelector<HTMLElement>('[data-demo]');
const objectif = démo?.querySelector<HTMLElement>('[data-demo-goal]');
const marque = objectif?.querySelector('i');
const score = démo?.querySelector<HTMLElement>('[data-demo-score]');
const xp = démo?.querySelector<HTMLElement>('[data-demo-xp]');
if (!animationRefusée && démo && objectif && marque && score && xp) {
	let validé = false;
	setInterval(() => {
		validé = !validé;
		démo.classList.toggle('valide', validé);
		objectif.classList.toggle('done', validé);
		marque.textContent = validé ? '✓' : '○';
		score.textContent = `${validé ? 3 : 2} / 3 validés`;
		xp.textContent = `+ ${validé ? 150 : 100} XP`;
	}, 3600);
}

/**
 * Page d'accueil : les parcours en onglets. Le HTML les empile (tout reste lisible sans JS) ;
 * on n'en garde qu'un visible à la fois une fois ce script exécuté.
 */
for (const groupe of document.querySelectorAll<HTMLElement>('[data-parcours]')) {
	const onglets = [...groupe.querySelectorAll<HTMLButtonElement>('[role="tab"]')];
	const panneaux = onglets.map((onglet) => document.getElementById(onglet.getAttribute('aria-controls') ?? ''));
	if (onglets.length < 2 || panneaux.some((panneau) => !panneau)) continue;

	const afficher = (choisi: number, donnerLeFocus: boolean) => {
		onglets.forEach((onglet, i) => {
			onglet.setAttribute('aria-selected', String(i === choisi));
			onglet.tabIndex = i === choisi ? 0 : -1;
			panneaux[i]!.hidden = i !== choisi;
		});
		if (donnerLeFocus) onglets[choisi].focus();
	};

	onglets.forEach((onglet, i) => {
		onglet.addEventListener('click', () => afficher(i, false));
		onglet.addEventListener('keydown', (event) => {
			const suivant = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: onglets.length - 1 }[event.key];
			if (undefined === suivant) return;
			event.preventDefault();
			afficher((suivant + onglets.length) % onglets.length, true);
		});
	});

	afficher(0, false);
	groupe.dataset.ready = '';
}

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
const chaptersToggle = document.querySelector<HTMLButtonElement>('[data-chapters-toggle]');
if (chaptersToggle) {
	const chapters = [...document.querySelectorAll<HTMLDetailsElement>('.tr-chapter')];
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
			const details = document.getElementById(link.dataset.openChapter!)?.querySelector<HTMLDetailsElement>('.tr-chapter');
			if (details) details.open = true;
		});
	}
	// Arrivée par une ancre (#chapitre-3), ou ancre changée ensuite : le chapitre s'ouvre.
	const openFromHash = () => {
		const target = location.hash ? document.getElementById(location.hash.slice(1))?.querySelector<HTMLDetailsElement>('.tr-chapter') : null;
		if (target) target.open = true;
	};
	openFromHash();
	window.addEventListener('hashchange', openFromHash);
}
