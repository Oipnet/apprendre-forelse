import { estModifiable } from './editable';

/** Nœud de l'arbre : un dossier (enfants) ou un fichier (chemin complet). */
interface Node {
	name: string;
	path: string;
	children?: Map<string, Node>;
}

function build(paths: string[]): Map<string, Node> {
	const racine = new Map<string, Node>();
	for (const path of paths) {
		let niveau = racine;
		const segments = path.split('/');
		segments.forEach((name, index) => {
			const chemin = segments.slice(0, index + 1).join('/');
			const dossier = index < segments.length - 1;
			let node = niveau.get(name);
			if (!node) {
				node = { name, path: chemin, ...(dossier ? { children: new Map() } : {}) };
				niveau.set(name, node);
			}
			if (dossier) niveau = node.children!;
		});
	}

	return racine;
}

/**
 * Explorateur de fichiers : tout le projet, pas seulement les fichiers de l'exercice.
 * Les fichiers modifiables sont signalés ; les autres s'ouvrent en lecture seule.
 */
export class FileTree {
	private readonly buttons = new Map<string, HTMLButtonElement>();

	constructor(
		private readonly root: HTMLElement,
		paths: string[],
		private readonly editable: string[],
		private readonly onOpen: (path: string) => void,
	) {
		this.root.replaceChildren(this.render(build(paths)));
	}

	/** Un dossier s'ouvre d'emblée s'il mène à un fichier modifiable. */
	private contientDuModifiable(node: Node): boolean {
		return this.editable.some((path) => path === node.path || path.startsWith(`${node.path}/`));
	}

	private render(nodes: Map<string, Node>): HTMLElement {
		const liste = document.createElement('div');
		liste.className = 'filetree-list';
		for (const node of [...nodes.values()].sort((a, b) => (!!b.children === !!a.children ? a.name.localeCompare(b.name) : a.children ? -1 : 1))) {
			if (node.children) {
				const details = document.createElement('details');
				details.open = this.contientDuModifiable(node);
				const summary = document.createElement('summary');
				summary.textContent = node.name;
				details.append(summary, this.render(node.children));
				liste.append(details);
				continue;
			}
			const bouton = document.createElement('button');
			bouton.type = 'button';
			bouton.textContent = node.name;
			bouton.title = node.path;
			if (estModifiable(node.path, this.editable)) bouton.classList.add('editable');
			bouton.addEventListener('click', () => this.onOpen(node.path));
			this.buttons.set(node.path, bouton);
			liste.append(bouton);
		}

		return liste;
	}

	/** Met en évidence le fichier affiché dans l'éditeur. */
	setActive(path: string) {
		for (const [chemin, bouton] of this.buttons) bouton.classList.toggle('active', chemin === path);
	}
}
