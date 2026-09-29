import { escapeHtml } from './html';

/**
 * Les boîtes de dialogue du playground, à la place de confirm() et alert() : dans le thème de la page, lisibles au
 * clavier et aux lecteurs d'écran (<dialog> modal, titre relié par aria-labelledby, focus sur le bouton prudent).
 */
let compteur = 0;

function open(host: HTMLElement, title: string, message: string, buttons: { label: string; value: string; primary?: boolean }[], focus: string): Promise<string> {
	const id = `dialog-title-${++compteur}`;
	const dialog = document.createElement('dialog');
	dialog.className = 'feedback confirm';
	dialog.setAttribute('aria-labelledby', id);
	dialog.innerHTML = `
		<h2 id="${id}">${escapeHtml(title)}</h2>
		<p>${escapeHtml(message)}</p>
		<p class="confirm-actions">${buttons.map((b) => `<button type="button" class="${b.primary ? 'primary' : 'ghost'}" data-value="${escapeHtml(b.value)}">${escapeHtml(b.label)}</button>`).join(' ')}</p>`;
	host.append(dialog);
	return new Promise((resolve) => {
		let value = '';
		dialog.querySelectorAll<HTMLButtonElement>('[data-value]').forEach((button) =>
			button.addEventListener('click', () => {
				value = button.dataset.value!;
				dialog.close();
			}),
		);
		// Échap ferme sans choisir : c'est « annuler ».
		dialog.addEventListener('close', () => {
			dialog.remove();
			resolve(value);
		});
		dialog.showModal();
		dialog.querySelector<HTMLButtonElement>(`[data-value="${focus}"]`)?.focus();
	});
}

/** Une question qui engage (solution, réinitialisation) : vrai seulement si l'apprenant confirme. */
export async function confirmDialog(host: HTMLElement, options: { title: string; message: string; confirm: string; cancel?: string }): Promise<boolean> {
	const buttons = [
		{ label: options.cancel ?? 'Annuler', value: 'cancel' },
		{ label: options.confirm, value: 'ok', primary: true },
	];
	return (await open(host, options.title, options.message, buttons, 'cancel')) === 'ok';
}

/** Une information à lire, un seul bouton. */
export async function noticeDialog(host: HTMLElement, title: string, message: string): Promise<void> {
	await open(host, title, message, [{ label: 'Compris', value: 'ok', primary: true }], 'ok');
}

/** Un message éphémère avec une action (« Annuler » après une réinitialisation), retiré après `ms`. */
export function undoToast(host: HTMLElement, message: string, action: string, onAction: () => void, ms = 10_000): HTMLElement {
	const toast = document.createElement('div');
	toast.className = 'undo-toast';
	toast.setAttribute('role', 'status');
	toast.innerHTML = `<span>${escapeHtml(message)}</span> <button type="button" class="ghost small">${escapeHtml(action)}</button>`;
	const timer = setTimeout(() => toast.remove(), ms);
	toast.querySelector('button')!.addEventListener('click', () => {
		clearTimeout(timer);
		toast.remove();
		onAction();
	});
	host.append(toast);
	return toast;
}
