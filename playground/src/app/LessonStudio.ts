import { EditorPanel } from '../editor/monaco';

/** Configuration passée par la page Twig de l'éditeur de fiche. */
export interface LessonStudioConfig {
	markdown: string;
	modifiable: boolean;
	/** Une clé d'API est configurée : le modèle peut rédiger un brouillon. */
	ia: boolean;
	urls: { enregistrer: string; apercu: string; squelette: string; rediger: string; lire: string; atelier: string };
}

const FICHIER = 'lesson.md';

/**
 * L'éditeur de fiche de cours de l'atelier : le markdown à gauche, à droite l'aperçu rendu
 * par le serveur, donc exactement ce que la plateforme affichera.
 */
export function mountLessonStudio(root: HTMLElement, config: LessonStudioConfig) {
	const $ = <T extends HTMLElement>(selector: string) => root.querySelector<T>(selector)!;
	let markdown = config.markdown;
	let modifie = false;
	let apercuTimer: ReturnType<typeof setTimeout> | undefined;

	const etat = (texte: string, genre: 'idle' | 'busy' | 'ok' | 'ko' = 'idle') => {
		const el = $('.status');
		el.textContent = texte;
		el.dataset.kind = genre;
	};

	const poster = async (url: string, corps: unknown, methode = 'POST') => {
		const reponse = await fetch(url, { method: methode, headers: { 'content-type': 'application/json' }, body: JSON.stringify(corps) });
		const json = (await reponse.json().catch(() => ({}))) as Record<string, unknown>;
		return { ok: reponse.ok, statut: reponse.status, json };
	};

	// --- Aperçu -----------------------------------------------------------------------------
	const apercu = async () => {
		const { ok, json } = await poster(config.urls.apercu, { markdown });
		if (ok && typeof json.html === 'string') $('#apercu').innerHTML = json.html;
	};
	const programmerApercu = () => {
		clearTimeout(apercuTimer);
		apercuTimer = setTimeout(() => void apercu(), 500);
	};

	const editor = new EditorPanel($('#tabs'), $('#editor'), (_chemin, contenu) => {
		markdown = contenu;
		modifie = true;
		etat('Modifications non enregistrées');
		programmerApercu();
	});
	editor.addFile(FICHIER, markdown, !config.modifiable);
	editor.open(FICHIER);
	void apercu();

	/** Remplace le contenu de l'éditeur par une proposition : l'auteur relit, puis enregistre. */
	const proposer = (contenu: string) => {
		editor.removeFile(FICHIER);
		markdown = contenu;
		modifie = true;
		editor.addFile(FICHIER, markdown, !config.modifiable);
		editor.open(FICHIER);
		programmerApercu();
	};

	// --- Enregistrer ------------------------------------------------------------------------
	const enregistrer = async () => {
		etat('Enregistrement…', 'busy');
		const { ok, statut, json } = await poster(config.urls.enregistrer, { markdown }, 'PUT');
		if (!ok) {
			etat((json.erreur as string | undefined) ?? `Enregistrement refusé (${statut})`, 'ko');
			return;
		}
		modifie = false;
		etat(json.fiche ? 'Enregistré' : 'Fiche retirée du chapitre', 'ok');
	};
	$('#enregistrer').addEventListener('click', () => void enregistrer());

	// --- Squelette / brouillon --------------------------------------------------------------
	const demander = async (url: string, attente: string) => {
		if (modifie && !confirm('Le contenu actuel sera remplacé par la proposition. Continuer ?')) return;
		const boutons = root.querySelectorAll<HTMLButtonElement>('.actions button');
		for (const bouton of boutons) bouton.disabled = true;
		etat(attente, 'busy');
		try {
			const { ok, statut, json } = await poster(url, {});
			if (!ok || typeof json.markdown !== 'string') {
				etat((json.erreur as string | undefined) ?? `Proposition refusée (${statut})`, 'ko');
				return;
			}
			proposer(json.markdown);
			etat('Proposition chargée : relisez, puis enregistrez', 'ok');
		} finally {
			for (const bouton of boutons) bouton.disabled = false;
		}
	};
	$('#squelette').addEventListener('click', () => void demander(config.urls.squelette, 'Assemblage du squelette…'));
	root.querySelector('#rediger')?.addEventListener('click', () => void demander(config.urls.rediger, 'Le modèle rédige la fiche…'));

	// --- Supprimer --------------------------------------------------------------------------
	$('#supprimer-fiche').addEventListener('click', async () => {
		if (!confirm('Retirer la fiche de ce chapitre ? Le fichier lesson.md est supprimé.')) return;
		proposer('');
		await enregistrer();
	});

	window.addEventListener('beforeunload', (e) => {
		if (modifie) e.preventDefault();
	});
}
