/** L'état affiché dans la barre de statut : neutre, en cours, réussi, en échec. */
export type StatusKind = 'idle' | 'busy' | 'ok' | 'ko';

/** Écrit dans la barre de statut ; `spoken` remplace, pour un lecteur d'écran, un texte trop abrégé. */
export type Status = (text: string, kind?: StatusKind, spoken?: string) => void;

/** La barre de statut du playground, annoncée (role="status") aux lecteurs d'écran. */
export function statusBar(el: HTMLElement): Status {
	return (text, kind = 'idle', spoken) => {
		if (spoken) {
			const visible = document.createElement('span');
			visible.setAttribute('aria-hidden', 'true');
			visible.textContent = text;
			const said = document.createElement('span');
			said.className = 'sr-only';
			said.textContent = spoken;
			el.replaceChildren(visible, said);
		} else {
			el.textContent = text;
		}
		el.dataset.kind = kind;
	};
}
