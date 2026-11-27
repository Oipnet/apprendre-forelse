import { m } from '../i18n';
import { markdown } from './markdown';

/** Où en est l'apprenant guidé : modifier le code, lancer les tests, corriger après un échec. */
export type GuideStep = 'write' | 'run' | 'fix';

/** Ce que chaque étape met en surbrillance. */
const TARGETS: Record<GuideStep, string> = {
	write: '#editor',
	run: '#run',
	fix: '.objectives',
};

/** Le temps sans frappe après lequel on propose de lancer les tests : pas au premier caractère effacé. */
export const PAUSE_MS = 1200;

export interface GuideOptions {
	/** Où retenir que l'apprenant a fermé le guide (localStorage) ; null : il revient à chaque visite. */
	storage?: Pick<Storage, 'getItem' | 'setItem'> | null;
	/** La clé de l'exercice dans ce stockage. */
	key?: string;
	/** Petit écran : l'éditeur et la consigne alternent, le texte dit où retrouver le code. */
	narrow?: () => boolean;
}

/**
 * Le guidage pas à pas de l'exercice d'accueil (clé `guide` de exercise.yaml) : une barre en bas de l'écran dit quoi
 * faire, et l'endroit où agir s'entoure d'un halo. ① modifier le code, ② après une pause de frappe, lancer les tests
 * (la consigne reste affichée), puis, après un échec, lire le message et corriger ; la réussite ferme le guide, et le
 * panneau de réussite prend le relais. Une barre plutôt qu'une bulle : elle se lit de la même façon sur un téléphone,
 * où l'éditeur et la consigne alternent.
 */
export class Guide {
	private readonly bar: HTMLElement;
	private readonly text: HTMLElement;
	private step: GuideStep | null = null;
	private pause: ReturnType<typeof setTimeout> | null = null;

	constructor(
		private readonly root: HTMLElement,
		/** Ce qu'il faut écrire, en markdown d'une ligne. */
		private readonly instruction: string,
		private readonly options: GuideOptions = {},
	) {
		this.bar = document.createElement('aside');
		this.bar.className = 'guide';
		this.bar.setAttribute('aria-label', m.guide.label);
		this.bar.innerHTML = `<p class="guide-text" role="status"></p><button type="button" class="ghost small guide-close" aria-label="${m.guide.close}" title="${m.guide.close}">✕</button>`;
		this.text = this.bar.querySelector<HTMLElement>('.guide-text')!;
		this.bar.querySelector('button')!.addEventListener('click', () => this.close());
	}

	get current(): GuideStep | null {
		return this.step;
	}

	/**
	 * Affiche le guide, sauf s'il a été fermé sur cet exercice. `changed` : le code n'est plus celui de départ
	 * (brouillon repris), on propose directement de lancer les tests.
	 */
	start(changed = false): void {
		if (this.wasClosed()) return;
		this.root.classList.add('guided');
		// Barre insérée vide, remplie ensuite : un lecteur d'écran annonce un role="status" qui change.
		this.root.append(this.bar);
		requestAnimationFrame(() => this.show(changed ? 'run' : 'write'));
	}

	/** L'apprenant écrit : après une pause, il peut lancer les tests. */
	edited(): void {
		if (this.step !== 'write' && this.step !== 'fix') return;
		if (this.pause) clearTimeout(this.pause);
		this.pause = setTimeout(() => {
			this.pause = null;
			if (this.step === 'write' || this.step === 'fix') this.show('run');
		}, PAUSE_MS);
	}

	/** Les tests ont tourné : la réussite ferme le guide, un échec (ou une erreur) invite à corriger. */
	testsDone(passed: number, total: number): void {
		if (this.step === null) return;
		if (passed === total) this.stop();
		else this.show('fix');
	}

	/** La réussite : le guide s'efface, le panneau de réussite prend le relais. */
	stop(): void {
		if (this.pause) clearTimeout(this.pause);
		this.pause = null;
		this.highlight(null);
		this.step = null;
		this.root.classList.remove('guided');
		this.bar.remove();
	}

	/** La croix : le guide ne revient pas sur cet exercice, dans ce navigateur. */
	private close(): void {
		this.stop();
		try {
			if (this.options.key) this.options.storage?.setItem(this.options.key, 'ferme');
		} catch {
			// Stockage indisponible (navigation privée) : le guide reviendra, rien de grave.
		}
	}

	private wasClosed(): boolean {
		try {
			return !!this.options.key && this.options.storage?.getItem(this.options.key) === 'ferme';
		} catch {
			return false;
		}
	}

	private show(step: GuideStep): void {
		this.step = step;
		const consigne = markdown(this.instruction, true);
		this.text.innerHTML = step === 'fix' ? m.guide.fix(this.options.narrow?.() ?? false) : m.guide[step](consigne);
		this.highlight(step);
	}

	private highlight(step: GuideStep | null): void {
		for (const element of this.root.querySelectorAll('.guide-target')) element.classList.remove('guide-target');
		if (step) this.root.querySelector(TARGETS[step])?.classList.add('guide-target');
	}
}
