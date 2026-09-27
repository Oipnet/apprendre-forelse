import type { BootProgress } from '@forelse/runtime-contract';
import type { EditorPanel } from '../editor/monaco';

export type Workpane = 'brief' | 'code' | 'preview';
type Pane = 'preview' | 'console' | 'requests';

/**
 * Petits écrans : un volet à la fois (consignes, code, aperçu). Rend `revealPane`, qui amène l'apprenant,
 * sur petit écran seulement, sur le volet où quelque chose vient de se passer.
 */
export function bindWorkpanes(root: HTMLElement, narrow: MediaQueryList): (pane: Workpane) => void {
	const workspace = root.querySelector<HTMLElement>('.workspace')!;
	const buttons = root.querySelectorAll<HTMLButtonElement>('.pane-switch button');
	const showWorkpane = (pane: Workpane) => {
		workspace.dataset.pane = pane;
		for (const b of buttons) b.classList.toggle('active', b.dataset.pane === pane);
	};
	for (const b of buttons) b.addEventListener('click', () => showWorkpane(b.dataset.pane as Workpane));
	const note = root.querySelector<HTMLElement>('.small-screen-note')!;
	note.querySelector('button')!.addEventListener('click', () => (note.style.display = 'none'));
	return (pane) => void (narrow.matches && showWorkpane(pane));
}

/** Les onglets aperçu / console / requêtes. Afficher la console efface son badge et, si elle en a un, donne le focus au champ. */
export function bindPaneTabs(root: HTMLElement, hasConsole: boolean): void {
	const $ = <T extends HTMLElement>(selector: string) => root.querySelector<T>(selector)!;
	const tabs = root.querySelectorAll<HTMLButtonElement>('.pane-tabs button');
	const showPane = (pane: Pane) => {
		for (const tab of tabs) tab.classList.toggle('active', tab.dataset.pane === pane);
		for (const name of ['preview', 'console', 'requests'] as const) $(`.pane-${name}`).hidden = pane !== name;
		if (pane === 'console') {
			$('.pane-tabs .badge').hidden = true;
			if (hasConsole) $<HTMLInputElement>('#console-cmd').focus();
		}
	};
	for (const tab of tabs) tab.addEventListener('click', () => showPane(tab.dataset.pane as Pane));
}

/** L'écran de démarrage : l'étape en cours et sa barre de progression. */
export function bootProgress(root: HTMLElement): (progress: BootProgress) => void {
	const label = root.querySelector<HTMLElement>('#boot-label')!;
	const bar = root.querySelector<HTMLElement>('#boot-bar')!;
	return (p) => {
		label.textContent = p.label;
		bar.style.width = p.ratio === null ? '100%' : `${Math.round(p.ratio * 100)}%`;
		bar.classList.toggle('indeterminate', p.ratio === null);
	};
}

/** Éditeur compact sur petit écran : police plus petite, retour à la ligne, gouttière réduite. Suit la taille de la fenêtre. */
export function fitEditor(editor: Pick<EditorPanel, 'instance'>, narrow: MediaQueryList): void {
	const fit = () =>
		editor.instance.updateOptions(
			narrow.matches ? { fontSize: 13, wordWrap: 'on', lineNumbersMinChars: 3, folding: false, glyphMargin: false } : { fontSize: 14, wordWrap: 'off', lineNumbersMinChars: 5, folding: true, glyphMargin: true },
		);
	fit();
	narrow.addEventListener('change', fit);
}
