/**
 * Les raccourcis du playground : lancer les tests, enregistrer, replier les consignes ou l'aperçu. Monaco les reçoit
 * d'abord quand il a le focus (voir Playground.ts, editor.addCommand) ; ailleurs dans la page, c'est l'écouteur du document.
 */
export type Shortcut = 'run' | 'save' | 'fold-brief' | 'fold-preview';

/**
 * Le raccourci que porte cette touche, s'il y en a un : Ctrl (⌘ sur Mac) + Entrée ou S ; Ctrl + Alt + B ou P pour
 * replier, Ctrl même sur Mac, où ⌘ + ⌥ + B ouvre les favoris du navigateur. La touche physique (`code`) plutôt que le
 * caractère : sur Mac, ⌥ + B tape « ∫ ».
 */
export function shortcutOf(event: Pick<KeyboardEvent, 'key' | 'code' | 'ctrlKey' | 'metaKey' | 'altKey' | 'shiftKey'>): Shortcut | null {
	if (event.ctrlKey && event.altKey && !event.metaKey && !event.shiftKey) {
		if (event.code === 'KeyB') return 'fold-brief';
		if (event.code === 'KeyP') return 'fold-preview';
		return null;
	}
	if (!(event.ctrlKey || event.metaKey) || event.altKey || event.shiftKey) return null;
	if (event.key === 'Enter') return 'run';
	if (event.key.toLowerCase() === 's') return 'save';
	return null;
}

/** « Ctrl » ou « ⌘ », selon la plateforme. */
export function modifierLabel(platform: string): string {
	return /Mac|iPhone|iPad/.test(platform) ? '⌘' : 'Ctrl';
}

/** Le raccourci qui replie un volet, tel qu'on le lit sur la plateforme : « Ctrl + Alt + B » ou « ⌃ + ⌥ + B ». */
export function foldShortcutLabel(platform: string, key: 'B' | 'P'): string {
	return /Mac|iPhone|iPad/.test(platform) ? `⌃ + ⌥ + ${key}` : `Ctrl + Alt + ${key}`;
}

/** Le contenu de l'aide « Raccourcis clavier ». */
export function shortcutsHelpHtml(mod: string, platform = ''): string {
	const row = (keys: string, what: string) => `<dt><kbd>${keys}</kbd></dt><dd>${what}</dd>`;
	return `
		<h2 id="shortcuts-title">Raccourcis clavier</h2>
		<dl class="shortcuts-list">
			${row(`${mod} + Entrée`, 'Lancer les tests')}
			${row(`${mod} + S`, 'Enregistrer le brouillon tout de suite')}
			${row(foldShortcutLabel(platform, 'B'), 'Replier ou afficher les consignes (écran large)')}
			${row(foldShortcutLabel(platform, 'P'), 'Replier ou afficher l\'aperçu (écran large)')}
			${row('Ctrl + M', 'Dans l\'éditeur : Tab passe au bouton suivant au lieu d\'indenter (et inversement)')}
			${row('F1', 'Dans l\'éditeur : toutes les commandes')}
			${row('Échap', 'Fermer cette aide')}
		</dl>
		<button type="button" class="ghost" data-close>Fermer</button>`;
}
