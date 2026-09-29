import { escapeHtml } from './html';
import { markdown } from './markdown';
import { renderExplanation, renderReview, type ErrorSource, type MentorClient, type Review } from './mentor';

export interface MentorPanelDeps {
	/** Le code de l'apprenant, envoyé avec chaque question. */
	files: () => Record<string, string>;
	/** Ouvre un fichier dans l'éditeur, à la ligne donnée s'il y en a une (liens « fichier:ligne »). */
	openAt: (path: string, line: number) => Promise<void>;
}

/**
 * Le mentor dans la page : « Expliquer l'erreur » sous l'aperçu, la console et la sortie des tests, et la
 * revue de code. Sans mentor (invité, pas de clé d'API), les boutons restent cachés.
 */
export class MentorPanel {
	/** La dernière erreur de chaque source, celle qu'on expliquerait. */
	private readonly errors: Partial<Record<ErrorSource, string>> = {};
	private readonly $ = <T extends HTMLElement>(selector: string) => this.root.querySelector<T>(selector)!;

	private deps: MentorPanelDeps | undefined;

	/**
	 * Créé avec la page : les erreurs de l'aperçu arrivent avant l'éditeur. Les boutons n'expliquent rien
	 * tant que connect() ne l'a pas relié au code de l'apprenant.
	 */
	constructor(
		private readonly root: HTMLElement,
		private readonly mentor: MentorClient | null,
		/** Sur petit écran : ramène l'apprenant sur les consignes, où la réponse s'affiche. */
		private readonly revealBrief: () => void,
	) {}

	/** Relie le mentor au code de l'apprenant et à l'éditeur : les boutons « Expliquer » répondent. */
	connect(deps: MentorPanelDeps): void {
		this.deps = deps;
		for (const source of ['preview', 'console', 'tests'] as const) {
			this.button(source).addEventListener('click', (e) => {
				if (source === 'tests') e.preventDefault(); // dans un <summary> : ne pas replier la sortie
				const error = this.errors[source];
				if (error) void this.explain(e.currentTarget as HTMLButtonElement, error, source);
			});
		}
	}

	private button(source: ErrorSource) {
		return this.$<HTMLButtonElement>(`#explain-${source}`);
	}

	/** La dernière erreur d'une source (null : plus d'erreur) ; son bouton ne s'affiche qu'avec un mentor. */
	setError(source: ErrorSource, error: string | null): void {
		if (error) this.errors[source] = error;
		else delete this.errors[source];
		this.button(source).hidden = !this.mentor || !error;
	}

	/** Les liens « fichier:ligne » du mentor ouvrent le fichier dans l'éditeur. */
	private bindFileLinks(host: HTMLElement) {
		for (const link of host.querySelectorAll<HTMLAnchorElement>('a[data-open]')) {
			link.addEventListener('click', async (event) => {
				event.preventDefault();
				await this.deps?.openAt(link.dataset.open!, Number(link.dataset.line));
			});
		}
	}

	showReview(review: Review): void {
		const box = this.$('#review');
		box.hidden = false;
		box.innerHTML = renderReview(review, (source) => markdown(source, true));
		this.bindFileLinks(box);
	}

	/** Le bouton « Demander une revue de code », proposé à la réussite ; null sans mentor. */
	reviewButton(): HTMLButtonElement | null {
		const mentor = this.mentor;
		const deps = this.deps;
		if (!mentor || !deps) return null;
		// Une revue de code, sur demande : un regard sur ce qui pourrait être plus idiomatique.
		const ask = document.createElement('button');
		ask.className = 'ghost small';
		ask.textContent = '🔍 Demander une revue de code';
		ask.addEventListener('click', async () => {
			ask.disabled = true;
			ask.textContent = 'Le mentor relit votre code…';
			try {
				this.showReview(await mentor.review(deps.files()));
				ask.remove();
			} catch (error) {
				ask.disabled = false;
				ask.textContent = '🔍 Demander une revue de code';
				this.$('#review').hidden = false;
				this.$('#review').innerHTML = `<strong>🔍 Revue de code</strong><p class="muted">${escapeHtml(error instanceof Error ? error.message : String(error))}</p>`;
			}
		});
		return ask;
	}

	/** Demande au mentor d'expliquer une erreur, et affiche sa réponse sous les objectifs. */
	private async explain(button: HTMLButtonElement, error: string, source: ErrorSource) {
		if (!this.mentor || !this.deps) return;
		const box = this.$('#explain');
		const label = button.textContent;
		button.disabled = true;
		button.textContent = 'Le mentor regarde…';
		box.hidden = false;
		box.innerHTML = '<strong><span aria-hidden="true">🩺</span> Le mentor</strong><p class="muted">Il lit l\'erreur et votre code…</p>';
		try {
			box.innerHTML = renderExplanation(await this.mentor.explain(this.deps.files(), error, source), (s) => markdown(s, true));
			this.bindFileLinks(box);
		} catch (e) {
			box.innerHTML = `<strong><span aria-hidden="true">🩺</span> Le mentor</strong><p class="muted">${escapeHtml(e instanceof Error ? e.message : String(e))}</p>`;
		} finally {
			button.disabled = false;
			button.textContent = label;
		}
		this.revealBrief();
		box.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
	}
}
