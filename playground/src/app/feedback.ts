/**
 * « Un avis ? » : retour d'un apprenant connecté sur l'exercice en cours.
 * Contrat serveur : App\Controller\Api\FeedbackApiController (POST JSON, 201).
 */
import { m } from '../i18n';
import { escapeHtml } from './html';

export const FEEDBACK_KINDS: { value: string; label: string }[] = (['bug', 'unclear', 'too-easy', 'too-hard', 'other'] as const).map((value) => ({ value, label: m.feedback.kinds[value] }));

export interface FeedbackContext {
	hintsUsed: number;
	completed: boolean;
}

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
				<h2>${m.feedback.title}</h2>
				<p class="meta">${m.feedback.intro}</p>
				<label>${m.feedback.kind}
					<select name="kind">${FEEDBACK_KINDS.map((k) => `<option value="${k.value}">${escapeHtml(k.label)}</option>`).join('')}</select>
				</label>
				<label>${m.feedback.message}
					<textarea name="message" rows="5" maxlength="2000" required placeholder="${m.feedback.placeholder}"></textarea>
				</label>
				<div class="feedback-actions">
					<span class="feedback-status" aria-live="polite"></span>
					<button type="button" class="ghost" data-close>${m.common.cancel}</button>
					<button type="submit" class="primary">${m.common.send}</button>
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
			status.textContent = m.common.sending;
			try {
				await this.send(String(data.get('kind')), message);
				status.textContent = m.feedback.thanks;
				form.reset();
				window.setTimeout(() => this.dialog.close(), 900);
			} catch (error) {
				status.textContent = m.feedback.failed(String(error instanceof Error ? error.message : error));
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
		if (response.status === 401) throw new Error(m.feedback.sessionExpired);
		if (response.status === 429) throw new Error(m.feedback.tooMany);
		if (!response.ok) throw new Error(m.feedback.httpError(response.status));
	}
}
