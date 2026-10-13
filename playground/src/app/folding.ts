import { m } from '../i18n';

/**
 * Volets repliables : sur un écran assez large pour en montrer plusieurs, les consignes et l'aperçu se replient en
 * une rail étroite pour laisser la place à l'éditeur. L'état se retient dans le navigateur, pour tous les exercices,
 * séparément pour l'écran large (trois colonnes) et l'écran moyen (consignes en bandeau). Sur petit écran, un volet à
 * la fois (voir panes.ts) : rien ne se replie, et un état retenu ailleurs n'y change rien.
 */
export type Foldable = 'brief' | 'preview';
export type Folded = Record<Foldable, boolean>;
/** Écran large (plus de 1100 px) ou moyen (800 à 1100 px) : la disposition, donc l'état retenu, diffère. */
export type FoldMode = 'large' | 'moyen';

type Store = Pick<Storage, 'getItem' | 'setItem'>;

export const FOLDABLES: readonly Foldable[] = ['brief', 'preview'];

export const foldKey = (mode: FoldMode) => `forelse:volets:${mode}`;

/** L'état retenu, tout ouvert à la première visite, si le stockage est indisponible ou si sa valeur est illisible. */
export function readFolded(store: Store | null, mode: FoldMode): Folded {
	const folded: Folded = { brief: false, preview: false };
	try {
		const saved = JSON.parse(store?.getItem(foldKey(mode)) ?? 'null') as unknown;
		if (saved && typeof saved === 'object') for (const pane of FOLDABLES) folded[pane] = (saved as Record<string, unknown>)[pane] === true;
	} catch {
		// stockage bloqué ou valeur abîmée : tout ouvert
	}
	return folded;
}

export function writeFolded(store: Store | null, mode: FoldMode, folded: Folded): void {
	try {
		store?.setItem(foldKey(mode), JSON.stringify(folded));
	} catch {
		// stockage indisponible : l'état ne sera simplement pas retenu
	}
}

/** Le bouton qui replie, ou la rail qui rouvre : ce qu'il annonce. */
export const foldLabel = (pane: Foldable, folded: boolean) => (folded ? m.folding.show : m.folding.hide)[pane];

/**
 * La pastille de la rail des consignes après un run : ✓ si tout passe, sinon le nombre d'objectifs en échec. `spoken`
 * complète le nom de la rail pour un lecteur d'écran.
 */
export function briefBadge(passed: number, total: number): { text: string; kind: 'ok' | 'ko'; spoken: string } {
	if (passed === total) return { text: '✓', kind: 'ok', spoken: m.folding.allPassed };
	const failed = total - passed;
	return { text: String(failed), kind: 'ko', spoken: m.folding.failing(failed) };
}

/** Le HTML d'une rail : un vrai bouton, son nom écrit verticalement, une pastille cachée tant qu'il n'y a rien à signaler. */
export function railHtml(pane: Foldable, icon: string, title: string, shortcut: string): string {
	return `<button type="button" class="pane-rail" data-rail="${pane}" aria-controls="pane-${pane}" aria-expanded="false" title="${foldLabel(pane, true)} (${shortcut})" hidden><span class="rail-icon" aria-hidden="true">${icon}</span><span class="rail-name">${title}</span><span class="rail-badge" aria-hidden="true" hidden></span><span class="sr-only rail-spoken"></span></button>`;
}

/** Le bouton qui replie un volet, dans son en-tête. Le chevron pointe vers le bord où le volet se range. */
export function foldButtonHtml(pane: Foldable, shortcut: string): string {
	const chevron = pane === 'brief' ? '‹' : '›';
	return `<button type="button" class="pane-fold" data-fold="${pane}" aria-controls="pane-${pane}" aria-expanded="true" aria-label="${foldLabel(pane, false)}" title="${foldLabel(pane, false)} (${shortcut})"><span aria-hidden="true">${chevron}</span></button>`;
}

/** Ce qui se passe quand un volet se replie ou se rouvre : la mesure d'audience, par exemple. */
type OnToggle = (pane: Foldable, folded: boolean) => void;

export class PaneFolding {
	private folded: Folded;
	private readonly workspace: HTMLElement;

	constructor(
		private readonly root: HTMLElement,
		/** Petit écran (un volet à la fois) : rien ne se replie. */
		private readonly narrow: MediaQueryList,
		/** Écran moyen : consignes en bandeau au-dessus. */
		private readonly compact: MediaQueryList,
		private readonly store: Store | null,
		private readonly onToggle: OnToggle = () => {},
	) {
		this.workspace = root.querySelector<HTMLElement>('.workspace')!;
		this.folded = readFolded(store, this.mode);
		for (const pane of FOLDABLES) {
			this.foldButton(pane).addEventListener('click', () => this.toggle(pane));
			this.rail(pane).addEventListener('click', () => this.toggle(pane));
		}
		// Passer d'une disposition à l'autre (fenêtre redimensionnée) : l'état retenu pour la nouvelle.
		const relayout = () => {
			this.folded = readFolded(store, this.mode);
			this.apply();
		};
		narrow.addEventListener('change', relayout);
		compact.addEventListener('change', relayout);
		this.apply();
	}

	private get mode(): FoldMode {
		return this.compact.matches ? 'moyen' : 'large';
	}

	isFolded(pane: Foldable): boolean {
		return !this.narrow.matches && this.folded[pane];
	}

	/** Replie ou rouvre (bouton, rail ou raccourci). Sans effet sur petit écran. */
	toggle(pane: Foldable): void {
		if (this.narrow.matches) return;
		this.set(pane, !this.folded[pane]);
	}

	/** Rouvre un volet replié : à la réussite, pour un indice, la solution ou le mentor. */
	open(pane: Foldable): void {
		if (this.isFolded(pane)) this.set(pane, false);
	}

	/** Le résultat du dernier run, signalé sur la rail tant que les consignes sont repliées. */
	showResult(passed: number, total: number): void {
		const badge = briefBadge(passed, total);
		this.badge('brief', badge.text, badge.kind, badge.spoken);
	}

	/** Un problème signalé dans la console (même pastille « ! » que son onglet), visible sur la rail de l'aperçu. */
	showPreviewAlert(spoken: string): void {
		this.badge('preview', '!', 'ko', spoken);
	}

	private set(pane: Foldable, folded: boolean): void {
		const panel = this.panel(pane);
		const rail = this.rail(pane);
		const active = document.activeElement;
		// Le focus ne reste pas sur un élément qui disparaît : sur la rail quand le volet se range, sur son bouton
		// quand il revient. Ailleurs (l'éditeur, via le raccourci), il ne bouge pas.
		const focusWasInside = folded ? panel.contains(active) && active !== rail : active === rail;
		this.folded[pane] = folded;
		writeFolded(this.store, this.mode, this.folded);
		this.apply();
		if (focusWasInside) (folded ? rail : this.foldButton(pane)).focus();
		this.onToggle(pane, folded);
	}

	private apply(): void {
		for (const pane of FOLDABLES) {
			const folded = this.isFolded(pane);
			const panel = this.panel(pane);
			const rail = this.rail(pane);
			this.workspace.toggleAttribute(`data-${pane}-folded`, folded);
			panel.classList.toggle('folded', folded);
			// Rangé, le volet sort de l'ordre de tabulation et de l'arbre d'accessibilité ; seule sa rail reste.
			for (const child of panel.children) if (child !== rail) (child as HTMLElement).inert = folded;
			rail.hidden = !folded;
			if (!folded) this.badge(pane, null);
			this.foldButton(pane).setAttribute('aria-expanded', String(!folded));
		}
	}

	private badge(pane: Foldable, text: string | null, kind?: 'ok' | 'ko', spoken?: string): void {
		const rail = this.rail(pane);
		const badge = rail.querySelector<HTMLElement>('.rail-badge')!;
		// Le volet est ouvert : son contenu dit déjà tout, la pastille ne servirait qu'à la réouverture.
		const shown = text !== null && this.isFolded(pane);
		badge.hidden = !shown;
		badge.textContent = shown ? text : '';
		if (kind) badge.dataset.kind = kind;
		rail.querySelector('.rail-spoken')!.textContent = shown && spoken ? ` (${spoken})` : '';
	}

	private panel(pane: Foldable): HTMLElement {
		return this.root.querySelector<HTMLElement>(`#pane-${pane}`)!;
	}

	private rail(pane: Foldable): HTMLButtonElement {
		return this.root.querySelector<HTMLButtonElement>(`[data-rail="${pane}"]`)!;
	}

	private foldButton(pane: Foldable): HTMLButtonElement {
		return this.root.querySelector<HTMLButtonElement>(`[data-fold="${pane}"]`)!;
	}
}
