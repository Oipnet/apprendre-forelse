/**
 * Les raccourcis du playground : lancer les tests, enregistrer. Monaco les reçoit d'abord quand il a le focus (voir
 * Playground.ts, editor.addCommand) ; ailleurs dans la page, c'est l'écouteur du document.
 */
export type Shortcut = 'run' | 'save';

/** Le raccourci que porte cette touche, s'il y en a un : Ctrl (⌘ sur Mac) + Entrée ou S. */
export function shortcutOf(event: Pick<KeyboardEvent, 'key' | 'ctrlKey' | 'metaKey' | 'altKey' | 'shiftKey'>): Shortcut | null {
	if (!(event.ctrlKey || event.metaKey) || event.altKey || event.shiftKey) return null;
	if (event.key === 'Enter') return 'run';
	if (event.key.toLowerCase() === 's') return 'save';
	return null;
}

/** « Ctrl » ou « ⌘ », selon la plateforme. */
export function modifierLabel(platform: string): string {
	return /Mac|iPhone|iPad/.test(platform) ? '⌘' : 'Ctrl';
}

/** Le contenu de l'aide « Raccourcis clavier ». */
export function shortcutsHelpHtml(mod: string): string {
	const row = (keys: string, what: string) => `<dt><kbd>${keys}</kbd></dt><dd>${what}</dd>`;
	return `
		<h2 id="shortcuts-title">Raccourcis clavier</h2>
		<dl class="shortcuts-list">
			${row(`${mod} + Entrée`, 'Lancer les tests')}
			${row(`${mod} + S`, 'Enregistrer le brouillon tout de suite')}
			${row('Ctrl + M', 'Dans l\'éditeur : Tab passe au bouton suivant au lieu d\'indenter (et inversement)')}
			${row('F1', 'Dans l\'éditeur : toutes les commandes')}
			${row('Échap', 'Fermer cette aide')}
		</dl>
		<button type="button" class="ghost" data-close>Fermer</button>`;
}
