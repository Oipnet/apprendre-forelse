/**
 * « Avant / après » : le code de départ face à celui de l'apprenant, fichier par fichier, dans l'éditeur de
 * différences de Monaco (lecture seule). Proposé à la réussite d'un exercice de Pratique.
 */
import { languageOf, monaco } from '../editor/monaco';

const escapeHtml = (s: string) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!);

export class BeforeAfterDialog {
	private readonly dialog: HTMLDialogElement;
	private diff: monaco.editor.IStandaloneDiffEditor | null = null;
	private models: monaco.editor.ITextModel[] = [];

	constructor(
		host: HTMLElement,
		/** Le code de départ, par chemin. */
		private readonly before: Record<string, string>,
		/** Le code de l'apprenant, lu au moment d'ouvrir. */
		private readonly after: () => Record<string, string>,
	) {
		this.dialog = document.createElement('dialog');
		this.dialog.className = 'before-after';
		this.dialog.innerHTML = `
			<header>
				<h2>Avant / après</h2>
				<select aria-label="Fichier"></select>
				<button type="button" class="ghost" data-close>Fermer</button>
			</header>
			<p class="meta"><span>Code de départ</span><span>Votre code</span></p>
			<div class="before-after-editor"></div>`;
		host.append(this.dialog);
		this.dialog.querySelector('[data-close]')!.addEventListener('click', () => this.dialog.close());
		this.dialog.addEventListener('close', () => this.dispose());
		this.dialog.querySelector('select')!.addEventListener('change', (event) => this.show((event.target as HTMLSelectElement).value));
	}

	open() {
		const after = this.after();
		// Les fichiers que l'apprenant a changés (ou créés), dans l'ordre du projet.
		const changed = Object.keys(after)
			.filter((path) => after[path] !== (this.before[path] ?? ''))
			.sort();
		const select = this.dialog.querySelector('select')!;
		select.innerHTML = changed.map((path) => `<option value="${escapeHtml(path)}">${escapeHtml(path)}</option>`).join('');
		select.hidden = changed.length < 2;
		this.dialog.showModal();
		if (!changed.length) {
			this.dialog.querySelector('.before-after-editor')!.textContent = 'Aucun fichier n’a changé.';
			return;
		}
		this.diff = monaco.editor.createDiffEditor(this.dialog.querySelector<HTMLElement>('.before-after-editor')!, {
			readOnly: true,
			originalEditable: false,
			automaticLayout: true,
			renderSideBySide: window.matchMedia('(min-width: 800px)').matches,
			minimap: { enabled: false },
			scrollBeyondLastLine: false,
			theme: 'vs-dark',
		});
		this.show(changed[0], after);
	}

	private show(path: string, after = this.after()) {
		if (!this.diff) return;
		const previous = this.models;
		const language = languageOf(path);
		this.models = [monaco.editor.createModel(this.before[path] ?? '', language), monaco.editor.createModel(after[path] ?? '', language)];
		this.diff.setModel({ original: this.models[0], modified: this.models[1] });
		for (const model of previous) model.dispose();
	}

	private dispose() {
		this.diff?.dispose();
		this.diff = null;
		for (const model of this.models) model.dispose();
		this.models = [];
		this.dialog.querySelector('.before-after-editor')!.textContent = '';
	}
}
