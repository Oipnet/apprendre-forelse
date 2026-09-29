import type { CommandResult, Runtime } from '@forelse/runtime-contract';
import type { EditorPanel } from '../editor/monaco';
import { confirmDialog, noticeDialog, undoToast } from './dialogs';
import { cheminsExplicites, contenuDeDepart, estModifiable } from './editable';
import { FileTree } from './filetree';
import type { ExerciseSession } from './session';
import type { ExercisePayload } from './types';

type Editor = Pick<EditorPanel, 'addFile' | 'has' | 'open' | 'setContent' | 'removeFile' | 'currentPath'>;

/**
 * Les fichiers du projet côté page : les onglets de l'éditeur, l'explorateur, les fichiers qu'une commande
 * crée ou supprime, la création d'un fichier et la réinitialisation.
 */
export class ProjectFilesController {
	private readonly $ = <T extends HTMLElement>(selector: string) => this.root.querySelector<T>(selector)!;
	private tree: FileTree | undefined;
	/** Tout le projet, tel que l'explorateur le montre. */
	private readonly paths = new Set<string>();
	/** Les tests cachés de l'exercice : ni dans l'explorateur, ni dans l'éditeur (ils donneraient la solution). */
	private readonly hiddenTests: Set<string>;

	constructor(
		private readonly root: HTMLElement,
		private readonly editor: Editor,
		private readonly runtime: Pick<Runtime, 'readFile' | 'writeFile' | 'deleteFile' | 'listFiles'>,
		private readonly exercise: ExercisePayload,
		private readonly session: ExerciseSession,
		/** Fenêtre étroite : l'explorateur prendrait la place du code, il démarre replié. */
		compact: boolean,
	) {
		this.hiddenTests = new Set(Object.keys(exercise.tests));
		this.bindNewFile();
		this.$('#toggle-tree').addEventListener('click', () => {
			const explorateur = this.$('#filetree');
			explorateur.hidden = !explorateur.hidden;
			this.$('#toggle-tree').classList.toggle('open', !explorateur.hidden);
		});
		if (compact) {
			// Un clic le rouvre.
			this.$('#filetree').hidden = true;
			this.$('#toggle-tree').classList.remove('open');
		}
		this.$('#reset').addEventListener('click', async () => {
			const ok = await confirmDialog(this.root, {
				title: 'Revenir au code de départ ?',
				message: 'Vos modifications seront remplacées par le code de départ. Vous pourrez annuler juste après.',
				confirm: 'Réinitialiser',
			});
			if (!ok) return;
			const avant = { ...this.session.current };
			this.reset();
			undoToast(this.root, 'Code de départ rétabli.', 'Annuler', () => this.restore(avant));
		});
	}

	/** Les onglets du départ : les fichiers nommés par l'exercice, ceux d'une session précédente, et les fichiers à lire. */
	async addInitialFiles(initial: Record<string, string>, editables: string[]): Promise<void> {
		const exercise = this.exercise;
		// Un motif (src/Entity/*.php) couvre souvent des fichiers déjà là : ils s'ouvrent depuis l'explorateur, pas tous d'emblée.
		const ouvertsAuDepart = new Set([...cheminsExplicites(exercise.editable), exercise.open, ...Object.keys(initial).filter((path) => !(path in exercise.files))]);
		for (const path of editables) if (ouvertsAuDepart.has(path)) this.editor.addFile(path, initial[path] ?? '');
		for (const path of exercise.readonly) this.editor.addFile(path, initial[path] ?? (await this.runtime.readFile(path)) ?? '', true);
	}

	/** Remplit l'explorateur avec tout le projet, sans attendre : il s'affiche quand la liste arrive. */
	load(): void {
		this.runtime
			.listFiles()
			.then((paths) => {
				for (const path of paths) this.paths.add(path);
				this.drawTree();
			})
			.catch((e) => console.warn('Explorateur indisponible', e));
	}

	private drawTree() {
		this.tree = new FileTree(this.$('#filetree-body'), [...this.paths].filter((path) => !this.hiddenTests.has(path)), this.exercise.editable, (path) => void this.openFile(path));
		const actif = this.editor.currentPath();
		if (actif) this.tree.setActive(actif);
	}

	/** Ouvre un fichier du projet dans l'éditeur (en lecture seule s'il n'est pas modifiable). */
	async openFile(path: string): Promise<void> {
		if (this.hiddenTests.has(path)) return;
		if (!this.editor.has(path)) {
			const content = await this.runtime.readFile(path);
			if (null === content) return;
			this.editor.addFile(path, content, !estModifiable(path, this.exercise.editable));
		}
		this.editor.open(path);
		this.tree?.setActive(path);
	}

	/** Reporte dans l'éditeur et l'explorateur ce qu'une commande a créé, modifié ou supprimé. */
	synchronise({ fichiers = {}, supprimes = [] }: Pick<CommandResult, 'fichiers' | 'supprimes'>): void {
		const editable = this.exercise.editable;
		const current = this.session.current;
		let nouveaux = false;
		let aOuvrir: string | undefined;
		for (const [path, content] of Object.entries(fichiers)) {
			if (this.hiddenTests.has(path)) continue;
			if (!this.paths.has(path)) {
				this.paths.add(path);
				nouveaux = true;
			}
			if (this.editor.has(path)) {
				this.editor.setContent(path, content);
			} else if (estModifiable(path, editable)) {
				// Un fichier généré que l'apprenant doit relire ou compléter : on l'ouvre tout de suite.
				this.editor.addFile(path, content);
				aOuvrir = path;
			}
			if (estModifiable(path, editable)) current[path] = content;
		}
		for (const path of supprimes) {
			if (this.editor.has(path)) this.editor.removeFile(path);
			delete current[path];
			nouveaux = this.paths.delete(path) || nouveaux;
		}
		if (aOuvrir) this.editor.open(aOuvrir);
		if (nouveaux) this.drawTree();
		if (Object.keys(fichiers).length || supprimes.length) this.session.saveDraft();
	}

	/** Créer un fichier, là où l'exercice le permet (un motif comme src/Entity/*.php). */
	private bindNewFile() {
		const editable = this.exercise.editable;
		const motifsModifiables = editable.filter((entree) => entree.includes('*'));
		const boutonNouveau = this.$<HTMLButtonElement>('#new-file');
		boutonNouveau.hidden = motifsModifiables.length === 0;
		boutonNouveau.addEventListener('click', async () => {
			const autorises = motifsModifiables.join(', ');
			const chemin = prompt(`Chemin du nouveau fichier (autorisés : ${autorises})`, motifsModifiables[0].replace('*', 'Nouveau'))?.trim().replace(/^\/+/, '');
			if (!chemin) return;
			if (chemin.split('/').includes('..') || !estModifiable(chemin, editable)) {
				void noticeDialog(this.root, 'Fichier non modifiable', `« ${chemin} » ne fait pas partie des fichiers modifiables de cet exercice (${autorises}).`);
				return;
			}
			if (this.paths.has(chemin) || this.editor.has(chemin)) {
				await this.openFile(chemin);
				return;
			}
			const contenu = contenuDeDepart(chemin, this.exercise.environment.framework);
			await this.runtime.writeFile(chemin, contenu);
			this.synchronise({ fichiers: { [chemin]: contenu } });
			this.tree?.setActive(chemin);
		});
	}

	/** Revient au code de départ. */
	private reset() {
		const exercise = this.exercise;
		const current = this.session.current;
		for (const path of Object.keys(current)) {
			if (path in exercise.files && !this.editor.has(path)) {
				// Couvert par un motif, jamais ouvert : on remet simplement le fichier de départ.
				current[path] = exercise.files[path];
				void this.runtime.writeFile(path, exercise.files[path]);
			} else if (path in exercise.files) {
				this.editor.setContent(path, exercise.files[path]);
			} else if (!cheminsExplicites(exercise.editable).includes(path)) {
				// Créé depuis le départ (par la console) : il n'existait pas, il disparaît.
				this.editor.removeFile(path);
				delete current[path];
				this.paths.delete(path);
				void this.runtime.deleteFile(path);
			} else {
				this.editor.setContent(path, '');
			}
		}
		this.drawTree();
		this.session.saveDraft();
	}

	/** Annule une réinitialisation : le code d'avant revient, fichiers créés depuis compris. */
	restore(avant: Record<string, string>): void {
		for (const [path, content] of Object.entries(avant)) {
			if (this.editor.has(path)) {
				this.editor.setContent(path, content);
			} else {
				this.session.current[path] = content;
				this.paths.add(path);
				void this.runtime.writeFile(path, content);
			}
		}
		this.drawTree();
		this.session.saveDraft();
	}

	/** Ouvre le fichier de départ de l'exercice. */
	openStart(): void {
		this.editor.open(this.exercise.open);
		this.tree?.setActive(this.exercise.open);
	}
}
