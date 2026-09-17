/**
 * « Un avis ? » : retour d'un apprenant connecté sur l'exercice en cours.
 * Contrat serveur : App\Controller\Api\FeedbackApiController (POST JSON, 201).
 */

export const FEEDBACK_KINDS: { value: string; label: string }[] = [
	{ value: 'bug', label: 'Un bogue (les tests, l’aperçu, l’éditeur…)' },
	{ value: 'unclear', label: 'L’énoncé n’est pas clair' },
	{ value: 'too-easy', label: 'Trop facile' },
	{ value: 'too-hard', label: 'Trop difficile' },
	{ value: 'other', label: 'Autre chose' },
];

export interface FeedbackContext {
	hintsUsed: number;
	completed: boolean;
}

const escapeHtml = (s: string) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!);

/** Monte le dialogue de retour et le relie au bouton `trigger`. */
export class FeedbackDialog {
	private readonly dialog: HTMLDialogElement;

	constructor(
		host: HTMLElement,
		trigger: HTMLButtonElement,
		private readonly url: string,
		private readonly context: () => FeedbackContext,
	) {
		this.dialog = document.createElement('dialog');
		this.dialog.className = 'feedback';
		this.dialog.innerHTML = `
			<form method="dialog" class="feedback-form">
				<h2>Un avis sur cet exercice ?</h2>
				<p class="meta">Chaque retour aide à améliorer la plateforme. Merci !</p>
				<label>Il s’agit de…
					<select name="kind">${FEEDBACK_KINDS.map((k) => `<option value="${k.value}">${escapeHtml(k.label)}</option>`).join('')}</select>
				</label>
				<label>Votre message
					<textarea name="message" rows="5" maxlength="2000" required placeholder="Ce qui a bloqué, ce qui manquait, ce qui a plu…"></textarea>
				</label>
				<div class="feedback-actions">
					<span class="feedback-status" aria-live="polite"></span>
					<button type="button" class="ghost" data-close>Annuler</button>
					<button type="submit" class="primary">Envoyer</button>
				</div>
			</form>`;
		host.append(this.dialog);

		const form = this.dialog.querySelector<HTMLFormElement>('form')!;
		const status = this.dialog.querySelector<HTMLElement>('.feedback-status')!;
		const submit = form.querySelector<HTMLButtonElement>('button[type=submit]')!;
		this.dialog.querySelector('[data-close]')!.addEventListener('click', () => this.dialog.close());
		trigger.addEventListener('click', () => {
			status.textContent = '';
			this.dialog.showModal();
			form.querySelector<HTMLTextAreaElement>('textarea')!.focus();
		});
		form.addEventListener('submit', async (event) => {
			event.preventDefault();
			const data = new FormData(form);
			const message = String(data.get('message') ?? '').trim();
			if (!message) return;
			submit.disabled = true;
			status.textContent = 'Envoi…';
			try {
				await this.send(String(data.get('kind')), message);
				status.textContent = 'Merci, c’est noté !';
				form.reset();
				window.setTimeout(() => this.dialog.close(), 900);
			} catch (error) {
				status.textContent = `Envoi impossible : ${String(error instanceof Error ? error.message : error)}`;
			} finally {
				submit.disabled = false;
			}
		});
	}

	private async send(kind: string, message: string) {
		const response = await fetch(this.url, {
			method: 'POST',
			headers: { accept: 'application/json', 'content-type': 'application/json' },
			body: JSON.stringify({ kind, message, ...this.context() }),
			credentials: 'same-origin',
		});
		if (response.status === 401) throw new Error('votre session a expiré, reconnectez-vous puis rechargez la page');
		if (!response.ok) throw new Error(`réponse ${response.status}`);
	}
}
