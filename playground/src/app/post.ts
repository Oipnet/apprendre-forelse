/** Configuration passée par la page Twig du post LinkedIn. */
export interface PostConfig {
	/** Une clé d'API est configurée : sans elle, la page n'affiche pas de formulaire. */
	ia: boolean;
	/** Longueur maximale d'un post LinkedIn. */
	maximum: number;
	/** Ce que LinkedIn montre avant « …voir plus ». */
	accroche: number;
	urls: { generer: string; publique: string };
}

interface Post {
	angle: string;
	label: string;
	texte: string;
	caracteres: number;
}

/**
 * Trois brouillons de post, à relire et à copier. Rien n'est enregistré : la page est un bloc-notes,
 * pas un éditeur de contenu — un post vit dans LinkedIn, pas dans le pack.
 */
export function mountPostStudio(root: HTMLElement, config: PostConfig) {
	const form = root.querySelector<HTMLFormElement>('.st-post-form');
	const results = root.querySelector<HTMLElement>('.st-post-results');
	if (!form || !results) return;

	const etat = form.querySelector<HTMLElement>('.st-post-etat')!;
	const erreur = form.querySelector<HTMLElement>('.erreur')!;
	const bouton = form.querySelector<HTMLButtonElement>('button[type=submit]')!;
	const precision = form.querySelector<HTMLTextAreaElement>('#precision')!;

	/** Le compteur : LinkedIn refuse au-delà du maximum, et coupe l'accroche bien avant. */
	const compter = (texte: string) => {
		const total = [...texte].length;
		const premiere = [...(texte.split('\n', 1)[0] ?? '')].length;
		const alertes = [];
		if (total > config.maximum) alertes.push(`${total - config.maximum} de trop`);
		if (premiere > config.accroche) alertes.push('accroche coupée');
		return { total, alerte: alertes.join(' · ') };
	};

	const copier = async (texte: string, zone: HTMLTextAreaElement, bouton: HTMLButtonElement) => {
		try {
			await navigator.clipboard.writeText(texte);
		} catch {
			// Pas de presse-papiers (page non sécurisée, permission refusée) : on sélectionne, l'auteur fait Ctrl+C.
			zone.focus();
			zone.select();
			bouton.textContent = 'Sélectionné : Ctrl+C';
			return;
		}
		bouton.textContent = 'Copié';
		setTimeout(() => (bouton.textContent = 'Copier'), 2000);
	};

	const carte = (post: Post) => {
		const article = document.createElement('article');
		article.className = 'st-post-card';

		const tete = document.createElement('p');
		tete.className = 'st-post-head';
		const label = document.createElement('span');
		label.className = 'st-post-angle';
		label.textContent = post.label;
		const mesure = document.createElement('span');
		mesure.className = 'st-post-count';
		tete.append(label, mesure);

		const zone = document.createElement('textarea');
		zone.className = 'st-post-texte';
		zone.rows = 14;
		zone.spellcheck = true;
		zone.setAttribute('aria-label', `Post « ${post.label} », modifiable avant de le copier`);
		zone.value = post.texte;

		const mesurer = () => {
			const { total, alerte } = compter(zone.value);
			mesure.textContent = alerte ? `${total} caractères — ${alerte}` : `${total} caractères`;
			mesure.dataset.alerte = alerte ? 'oui' : 'non';
		};
		zone.addEventListener('input', mesurer);
		mesurer();

		const copie = document.createElement('button');
		copie.type = 'button';
		copie.className = 'st-btn primary';
		copie.textContent = 'Copier';
		copie.addEventListener('click', () => void copier(zone.value, zone, copie));

		const pied = document.createElement('p');
		pied.className = 'st-post-foot';
		pied.append(copie);

		article.append(tete, zone, pied);
		return article;
	};

	form.addEventListener('submit', async (event) => {
		event.preventDefault();
		erreur.textContent = '';
		bouton.disabled = true;
		etat.textContent = 'Le modèle écrit les trois brouillons…';
		try {
			const reponse = await fetch(config.urls.generer, {
				method: 'POST',
				headers: { 'content-type': 'application/json', accept: 'application/json' },
				credentials: 'same-origin',
				body: JSON.stringify({ precision: precision.value }),
			});
			const corps = (await reponse.json().catch(() => ({}))) as { posts?: Post[]; erreur?: string; detail?: string };
			if (!reponse.ok || !corps.posts?.length) {
				erreur.textContent = corps.erreur ?? corps.detail ?? `Génération refusée (${reponse.status}).`;
				etat.textContent = '';
				return;
			}
			results.replaceChildren(...corps.posts.map(carte));
			etat.textContent = 'Relisez, ajustez, copiez.';
		} finally {
			bouton.disabled = false;
		}
	});
}
