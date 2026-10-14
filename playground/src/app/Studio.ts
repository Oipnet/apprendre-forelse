import { EditorPanel } from '../editor/monaco';
import { FileTree } from './filetree';
import { escapeHtml } from './html';

/** Configuration passée par la page Twig de l'atelier. */
export interface StudioConfig {
	fichiers: Record<string, string>;
	modifiable: boolean;
	/** Une clé d'API est configurée : la correction assistée est possible. */
	ia: boolean;
	/** Exercice de Pratique : pas de ligne dans track.yaml. */
	pratique?: boolean;
	/** La version anglaise d'un exercice de Pratique (i18n/en/) : les fichiers sont ceux de la traduction. */
	traduction?: boolean;
	urls: { enregistrer: string; verifier: string; corriger?: string; supprimer?: string; traduire?: string; jouer: string; atelier: string };
}

interface Verdict {
	ok: boolean;
	erreurs: string[];
	avertissements: string[];
	objectifs: { label: string; dejaValide: boolean }[];
	secondes: number;
}

/**
 * L'éditeur de l'atelier : les fichiers de l'exercice, tels qu'ils sont sur le disque.
 *
 * Enregistrer réécrit le dossier de l'exercice ; vérifier lance content:check et affiche
 * son verdict — tests rouges au départ, verts avec la solution.
 */
export async function mountStudio(root: HTMLElement, config: StudioConfig) {
	const $ = <T extends HTMLElement>(selector: string) => root.querySelector<T>(selector)!;
	const fichiers = new Map(Object.entries(config.fichiers));
	/** Chaque changement incrémente la version ; est enregistrée celle qui a été envoyée, pas forcément la dernière. */
	let version = 0;
	let versionEnregistree = 0;
	const modifie = () => version !== versionEnregistree;
	const changer = () => {
		version++;
	};

	const etat = (texte: string, genre: 'idle' | 'busy' | 'ok' | 'ko' = 'idle') => {
		const el = $('.status');
		el.textContent = texte;
		el.dataset.kind = genre;
	};

	const editor = new EditorPanel($('#tabs'), $('#editor'), (chemin, contenu) => {
		fichiers.set(chemin, contenu);
		changer();
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

	const ouvrirLePremier = () => {
		const premier = fichiers.has('exercise.yaml') ? 'exercise.yaml' : [...fichiers.keys()][0];
		if (premier) ouvrir(premier);
	};

	dessinerArbre();
	// Une traduction pas encore écrite n'a aucun fichier : l'éditeur attend la proposition du modèle, ou un ajout.
	ouvrirLePremier();
	if (config.traduction && fichiers.size === 0) etat(config.ia ? 'Pas encore de traduction : « Traduire avec l\'IA » la propose' : 'Pas encore de traduction : ajoutez exercise.yaml et instructions.md');

	// --- Ajouter / supprimer un fichier ---------------------------------------------------
	$('#ajouter').addEventListener('click', () => {
		const chemin = prompt('Chemin du nouveau fichier (starter/…, solution/…, tests/…)')?.trim();
		if (!chemin) return;
		if (fichiers.has(chemin)) return ouvrir(chemin);
		fichiers.set(chemin, '');
		changer();
		dessinerArbre();
		ouvrir(chemin);
	});
	$('#supprimer').addEventListener('click', () => {
		const chemin = editor.currentPath();
		if (!chemin || !confirm(`Supprimer « ${chemin} » ?`)) return;
		fichiers.delete(chemin);
		editor.removeFile(chemin);
		changer();
		dessinerArbre();
		const suivant = [...fichiers.keys()][0];
		if (suivant) ouvrir(suivant);
	});

	// --- Supprimer l'exercice --------------------------------------------------------------
	root.querySelector('#supprimer-exercice')?.addEventListener('click', async () => {
		if (!config.urls.supprimer || !confirm(config.pratique ? 'Supprimer cet exercice ? Son dossier disparaît.' : 'Supprimer cet exercice ? Son dossier et sa ligne dans track.yaml disparaissent.')) return;
		const reponse = await fetch(config.urls.supprimer, { method: 'DELETE' });
		const corps = (await reponse.json().catch(() => ({}))) as { url?: string; erreur?: string };
		if (reponse.ok && corps.url) {
			versionEnregistree = version;
			location.href = corps.url;
			return;
		}
		etat(corps.erreur ?? `Suppression refusée (${reponse.status})`, 'ko');
	});

	// --- Enregistrer ----------------------------------------------------------------------
	const enregistrer = async (): Promise<boolean> => {
		etat('Enregistrement…', 'busy');
		// Le corps est figé ici : une frappe pendant l'envoi reste « non enregistrée ».
		const envoyee = version;
		const reponse = await fetch(config.urls.enregistrer, {
			method: 'PUT',
			headers: { 'content-type': 'application/json' },
			body: JSON.stringify({ fichiers: Object.fromEntries(fichiers) }),
		});
		const corps = (await reponse.json().catch(() => ({}))) as { erreur?: string; format?: string | null };
		if (!reponse.ok) {
			// Format invalide : rien n'a été écrit, le travail reste ici.
			etat(corps.erreur ?? `Enregistrement refusé (${reponse.status})`, 'ko');
			if (corps.format) afficherVerdict({ ok: false, erreurs: [corps.format], avertissements: [], objectifs: [], secondes: 0 });
			return false;
		}
		versionEnregistree = Math.max(versionEnregistree, envoyee);
		if (modifie()) {
			etat('Enregistré, mais modifié depuis');
			return false;
		}
		etat('Enregistré', 'ok');
		return true;
	};
	$('#enregistrer').addEventListener('click', () => void enregistrer());

	// --- Vérifier -------------------------------------------------------------------------
	$('#verifier').addEventListener('click', async () => {
		const boutons = root.querySelectorAll<HTMLButtonElement>('.actions button');
		if (modifie() && !(await enregistrer())) return;
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

	// --- Corriger ou traduire avec l'IA ---------------------------------------------------
	const proposer = async (url: string, attente: string, echec: string) => {
		const boutons = root.querySelectorAll<HTMLButtonElement>('.actions button');
		for (const bouton of boutons) bouton.disabled = true;
		etat(attente, 'busy');
		try {
			const reponse = await fetch(url, { method: 'POST' });
			const corps = (await reponse.json().catch(() => ({}))) as { fichiers?: Record<string, string>; erreur?: string };
			if (!reponse.ok || !corps.fichiers) {
				etat(corps.erreur ?? echec, 'ko');
				return;
			}
			// Proposition, pas vérité : elle atterrit dans l'éditeur, l'auteur relit et enregistre.
			for (const chemin of [...fichiers.keys()]) editor.removeFile(chemin);
			fichiers.clear();
			for (const [chemin, contenu] of Object.entries(corps.fichiers)) fichiers.set(chemin, contenu);
			changer();
			dessinerArbre();
			ouvrirLePremier();
			etat('Proposition du modèle chargée : relisez, enregistrez, puis vérifiez', 'ok');
		} finally {
			for (const bouton of boutons) bouton.disabled = false;
		}
	};
	root.querySelector('#corriger')?.addEventListener('click', () => {
		if (config.urls.corriger) void proposer(config.urls.corriger, 'Le modèle relit l\'exercice…', 'La correction a échoué');
	});
	root.querySelector('#traduire')?.addEventListener('click', () => {
		if (!config.urls.traduire) return;
		if (fichiers.size > 0 && !confirm('Remplacer la traduction dans l\'éditeur par celle du modèle ? Rien n\'est enregistré tant que vous n\'enregistrez pas.')) return;
		void proposer(config.urls.traduire, 'Le modèle traduit l\'exercice (une à deux minutes)…', 'La traduction a échoué');
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
		if (modifie()) e.preventDefault();
	});
}
