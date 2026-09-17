import { EditorPanel } from '../editor/monaco';
import { FileTree } from './filetree';

/** Configuration passée par la page Twig de l'atelier. */
export interface StudioConfig {
	fichiers: Record<string, string>;
	modifiable: boolean;
	/** Une clé d'API est configurée : la correction assistée est possible. */
	ia: boolean;
	/** Exercice de Pratique : pas de ligne dans track.yaml. */
	pratique?: boolean;
	urls: { enregistrer: string; verifier: string; corriger: string; supprimer: string; jouer: string; atelier: string };
}

interface Verdict {
	ok: boolean;
	erreurs: string[];
	avertissements: string[];
	objectifs: { label: string; dejaValide: boolean }[];
	secondes: number;
}

const escapeHtml = (s: string) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!);

/**
 * L'éditeur de l'atelier : les fichiers de l'exercice, tels qu'ils sont sur le disque.
 *
 * Enregistrer réécrit le dossier de l'exercice ; vérifier lance content:check et affiche
 * son verdict — tests rouges au départ, verts avec la solution.
 */
export async function mountStudio(root: HTMLElement, config: StudioConfig) {
	const $ = <T extends HTMLElement>(selector: string) => root.querySelector<T>(selector)!;
	const fichiers = new Map(Object.entries(config.fichiers));
	let modifie = false;

	const etat = (texte: string, genre: 'idle' | 'busy' | 'ok' | 'ko' = 'idle') => {
		const el = $('.status');
		el.textContent = texte;
		el.dataset.kind = genre;
	};

	const editor = new EditorPanel($('#tabs'), $('#editor'), (chemin, contenu) => {
		fichiers.set(chemin, contenu);
		modifie = true;
		etat('Modifications non enregistrées');
	});

	let arbre: FileTree | undefined;
	const dessinerArbre = () => {
		// Tout est modifiable ici : inutile de marquer chaque fichier.
		arbre = new FileTree($('#filetree-body'), [...fichiers.keys()], [], (chemin) => ouvrir(chemin));
	};
	const ouvrir = (chemin: string) => {
		if (!editor.has(chemin)) editor.addFile(chemin, fichiers.get(chemin) ?? '', !config.modifiable);
		editor.open(chemin);
		arbre?.setActive(chemin);
	};

	dessinerArbre();
	ouvrir(fichiers.has('exercise.yaml') ? 'exercise.yaml' : [...fichiers.keys()][0]);

	// --- Ajouter / supprimer un fichier ---------------------------------------------------
	$('#ajouter').addEventListener('click', () => {
		const chemin = prompt('Chemin du nouveau fichier (starter/…, solution/…, tests/…)')?.trim();
		if (!chemin) return;
		if (fichiers.has(chemin)) return ouvrir(chemin);
		fichiers.set(chemin, '');
		modifie = true;
		dessinerArbre();
		ouvrir(chemin);
	});
	$('#supprimer').addEventListener('click', () => {
		const chemin = editor.currentPath();
		if (!chemin || !confirm(`Supprimer « ${chemin} » ?`)) return;
		fichiers.delete(chemin);
		editor.removeFile(chemin);
		modifie = true;
		dessinerArbre();
		const suivant = [...fichiers.keys()][0];
		if (suivant) ouvrir(suivant);
	});

	// --- Supprimer l'exercice --------------------------------------------------------------
	$('#supprimer-exercice').addEventListener('click', async () => {
		if (!confirm(config.pratique ? 'Supprimer cet exercice ? Son dossier disparaît.' : 'Supprimer cet exercice ? Son dossier et sa ligne dans track.yaml disparaissent.')) return;
		const reponse = await fetch(config.urls.supprimer, { method: 'DELETE' });
		const corps = (await reponse.json().catch(() => ({}))) as { url?: string; erreur?: string };
		if (reponse.ok && corps.url) {
			modifie = false;
			location.href = corps.url;
			return;
		}
		etat(corps.erreur ?? `Suppression refusée (${reponse.status})`, 'ko');
	});

	// --- Enregistrer ----------------------------------------------------------------------
	const enregistrer = async (): Promise<boolean> => {
		etat('Enregistrement…', 'busy');
		const reponse = await fetch(config.urls.enregistrer, {
			method: 'PUT',
			headers: { 'content-type': 'application/json' },
			body: JSON.stringify({ fichiers: Object.fromEntries(fichiers) }),
		});
		const corps = await reponse.json().catch(() => ({}));
		if (!reponse.ok) {
			etat(corps.erreur ?? `Enregistrement refusé (${reponse.status})`, 'ko');
			return false;
		}
		modifie = false;
		if (corps.format) {
			etat('Enregistré, mais le format est invalide', 'ko');
			afficherVerdict({ ok: false, erreurs: [corps.format], avertissements: [], objectifs: [], secondes: 0 });
			return false;
		}
		etat('Enregistré', 'ok');
		return true;
	};
	$('#enregistrer').addEventListener('click', () => void enregistrer());

	// --- Vérifier -------------------------------------------------------------------------
	$('#verifier').addEventListener('click', async () => {
		const boutons = root.querySelectorAll<HTMLButtonElement>('.actions button');
		if (modifie && !(await enregistrer())) return;
		for (const bouton of boutons) bouton.disabled = true;
		etat('Vérification en cours (tests au départ, puis avec la solution)…', 'busy');
		try {
			const reponse = await fetch(config.urls.verifier, { method: 'POST' });
			const verdict = (await reponse.json()) as Verdict;
			afficherVerdict(verdict);
			etat(verdict.ok ? `Exercice conforme (${verdict.secondes} s)` : `À corriger (${verdict.secondes} s)`, verdict.ok ? 'ok' : 'ko');
			const corriger = root.querySelector<HTMLButtonElement>('#corriger');
			if (corriger) corriger.hidden = verdict.ok || !config.ia;
		} catch (erreur) {
			etat(`La vérification a échoué : ${erreur}`, 'ko');
		} finally {
			for (const bouton of boutons) bouton.disabled = false;
		}
	});

	// --- Corriger avec l'IA ---------------------------------------------------------------
	root.querySelector('#corriger')?.addEventListener('click', async () => {
		const boutons = root.querySelectorAll<HTMLButtonElement>('.actions button');
		for (const bouton of boutons) bouton.disabled = true;
		etat('Le modèle relit l\'exercice…', 'busy');
		try {
			const reponse = await fetch(config.urls.corriger, { method: 'POST' });
			const corps = (await reponse.json()) as { fichiers?: Record<string, string>; erreur?: string };
			if (!reponse.ok || !corps.fichiers) {
				etat(corps.erreur ?? 'La correction a échoué', 'ko');
				return;
			}
			// Proposition, pas vérité : elle atterrit dans l'éditeur, l'auteur relit et enregistre.
			for (const [chemin, contenu] of Object.entries(corps.fichiers)) {
				fichiers.set(chemin, contenu);
				editor.removeFile(chemin);
			}
			for (const chemin of [...fichiers.keys()]) if (!(chemin in corps.fichiers)) fichiers.delete(chemin);
			modifie = true;
			dessinerArbre();
			ouvrir(fichiers.has('exercise.yaml') ? 'exercise.yaml' : [...fichiers.keys()][0]);
			etat('Proposition du modèle chargée : relisez, puis vérifiez', 'ok');
		} finally {
			for (const bouton of boutons) bouton.disabled = false;
		}
	});

	function afficherVerdict(verdict: Verdict) {
		const ligne = (texte: string, classe: string) => `<li class="${classe}">${escapeHtml(texte)}</li>`;
		const objectifs = verdict.objectifs.map((o) =>
			ligne(o.dejaValide ? `${o.label} — déjà validé au départ` : o.label, o.dejaValide ? 'ko' : 'ok'),
		);
		$('#verdict').innerHTML = `
			<h3>${verdict.ok ? '✔ Exercice conforme' : '✘ À corriger'}</h3>
			<ul>
				${verdict.erreurs.map((e) => ligne(e, 'ko')).join('')}
				${verdict.avertissements.map((a) => ligne(a, 'attention')).join('')}
				${objectifs.join('')}
			</ul>`;
		$('#verdict').hidden = false;
	}

	window.addEventListener('beforeunload', (e) => {
		if (modifie) e.preventDefault();
	});
}
