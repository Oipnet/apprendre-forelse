// @vitest-environment happy-dom
import { beforeEach, describe, expect, it } from 'vitest';
import { briefBadge, foldButtonHtml, foldKey, PaneFolding, railHtml, readFolded, writeFolded } from '../src/app/folding.ts';

class Memoire {
	readonly items = new Map<string, string>();
	getItem(key: string) {
		return this.items.get(key) ?? null;
	}
	setItem(key: string, value: string) {
		this.items.set(key, value);
	}
}

/** Une media query qu'on fait basculer à la main, comme une fenêtre qu'on redimensionne. */
class Ecran {
	private readonly listeners: (() => void)[] = [];
	constructor(public matches: boolean) {}
	addEventListener(_: 'change', listener: () => void) {
		this.listeners.push(listener);
	}
	set(matches: boolean) {
		this.matches = matches;
		for (const l of this.listeners) l();
	}
}

describe('état retenu', () => {
	it('tout ouvert à la première visite, avec une valeur abîmée, ou sans stockage', () => {
		const store = new Memoire();
		expect(readFolded(store, 'large')).toEqual({ brief: false, preview: false });
		store.setItem(foldKey('large'), '{pas du json');
		expect(readFolded(store, 'large')).toEqual({ brief: false, preview: false });
		expect(readFolded(null, 'large')).toEqual({ brief: false, preview: false });
	});

	it('relit ce qui a été écrit, séparément pour l\'écran large et moyen', () => {
		const store = new Memoire();
		writeFolded(store, 'large', { brief: true, preview: false });
		expect(readFolded(store, 'large')).toEqual({ brief: true, preview: false });
		expect(readFolded(store, 'moyen')).toEqual({ brief: false, preview: false });
	});

	it('un stockage qui refuse d\'écrire ne casse rien', () => {
		const plein = { getItem: () => null, setItem: () => { throw new Error('QuotaExceededError'); } };
		expect(() => writeFolded(plein, 'large', { brief: true, preview: true })).not.toThrow();
	});
});

describe('briefBadge', () => {
	it('✓ quand tout passe, sinon le nombre d\'objectifs en échec, dit en toutes lettres', () => {
		expect(briefBadge(3, 3)).toEqual({ text: '✓', kind: 'ok', spoken: 'tous les objectifs sont atteints' });
		expect(briefBadge(1, 3)).toEqual({ text: '2', kind: 'ko', spoken: '2 objectifs en échec' });
		expect(briefBadge(2, 3).spoken).toBe('1 objectif en échec');
	});
});

describe('PaneFolding', () => {
	let root: HTMLElement;
	let store: Memoire;
	const large = () => ({ narrow: new Ecran(false), compact: new Ecran(false) });

	beforeEach(() => {
		document.body.innerHTML = `<div id="root"><main class="workspace">
			<aside class="panel brief" id="pane-brief">${railHtml('brief', '📋', 'Consignes', 'Ctrl + Alt + B')}${foldButtonHtml('brief', 'Ctrl + Alt + B')}<p><a href="#">lien</a></p></aside>
			<section class="panel code"><textarea id="editeur"></textarea></section>
			<section class="panel preview" id="pane-preview">${railHtml('preview', '👁', 'Aperçu', 'Ctrl + Alt + P')}<nav class="pane-tabs">${foldButtonHtml('preview', 'Ctrl + Alt + P')}</nav><iframe></iframe></section>
		</main></div>`;
		root = document.querySelector('#root')!;
		store = new Memoire();
	});

	const $ = (selector: string) => root.querySelector<HTMLElement>(selector)!;

	it('replie un volet : rail affichée, contenu inerte, état retenu ; la rail le rouvre', () => {
		const { narrow, compact } = large();
		const toggles: [string, boolean][] = [];
		new PaneFolding(root, narrow as unknown as MediaQueryList, compact as unknown as MediaQueryList, store, (pane, folded) => toggles.push([pane, folded]));
		expect($('[data-rail="brief"]').hidden).toBe(true);

		$('[data-fold="brief"]').click();
		expect($('.workspace').hasAttribute('data-brief-folded')).toBe(true);
		expect($('[data-rail="brief"]').hidden).toBe(false);
		expect($('[data-rail="brief"]').getAttribute('aria-expanded')).toBe('false');
		expect(($('#pane-brief p') as HTMLElement & { inert: boolean }).inert).toBe(true);
		expect(readFolded(store, 'large')).toEqual({ brief: true, preview: false });

		$('[data-rail="brief"]').click();
		expect($('.workspace').hasAttribute('data-brief-folded')).toBe(false);
		expect($('[data-fold="brief"]').getAttribute('aria-expanded')).toBe('true');
		expect(toggles).toEqual([['brief', true], ['brief', false]]);
	});

	it('le focus suit le bouton qui disparaît, mais reste dans l\'éditeur après un raccourci', () => {
		const { narrow, compact } = large();
		const folding = new PaneFolding(root, narrow as unknown as MediaQueryList, compact as unknown as MediaQueryList, store);
		$('[data-fold="preview"]').focus();
		$('[data-fold="preview"]').click();
		expect(document.activeElement).toBe($('[data-rail="preview"]'));
		$('[data-rail="preview"]').click();
		expect(document.activeElement).toBe($('[data-fold="preview"]'));

		$('#editeur').focus();
		folding.toggle('brief');
		expect(document.activeElement).toBe($('#editeur'));
	});

	it('reprend l\'état retenu au chargement, et celui de la disposition quand la fenêtre change', () => {
		writeFolded(store, 'large', { brief: false, preview: true });
		const { narrow, compact } = large();
		const folding = new PaneFolding(root, narrow as unknown as MediaQueryList, compact as unknown as MediaQueryList, store);
		expect(folding.isFolded('preview')).toBe(true);
		compact.set(true);
		expect(folding.isFolded('preview')).toBe(false);
		compact.set(false);
		expect(folding.isFolded('preview')).toBe(true);
	});

	it('petit écran : rien ne se replie, même avec un état retenu', () => {
		writeFolded(store, 'moyen', { brief: true, preview: true });
		const folding = new PaneFolding(root, new Ecran(true) as unknown as MediaQueryList, new Ecran(true) as unknown as MediaQueryList, store);
		expect(folding.isFolded('brief')).toBe(false);
		expect($('.workspace').hasAttribute('data-brief-folded')).toBe(false);
		folding.toggle('brief');
		expect(folding.isFolded('brief')).toBe(false);
	});

	it('la rail porte le résultat des tests tant que le volet est replié, et open() le rouvre', () => {
		const { narrow, compact } = large();
		const folding = new PaneFolding(root, narrow as unknown as MediaQueryList, compact as unknown as MediaQueryList, store);
		folding.showResult(1, 3); // volet ouvert : rien à signaler sur la rail
		expect($('[data-rail="brief"] .rail-badge').hidden).toBe(true);
		folding.toggle('brief');
		folding.showResult(1, 3);
		expect($('[data-rail="brief"] .rail-badge').textContent).toBe('2');
		expect($('[data-rail="brief"] .rail-spoken').textContent).toBe(' (2 objectifs en échec)');
		folding.open('brief');
		expect(folding.isFolded('brief')).toBe(false);
		expect($('[data-rail="brief"] .rail-badge').hidden).toBe(true);
	});

	it('signale un problème de console sur la rail de l\'aperçu replié', () => {
		const { narrow, compact } = large();
		const folding = new PaneFolding(root, narrow as unknown as MediaQueryList, compact as unknown as MediaQueryList, store);
		folding.toggle('preview');
		folding.showPreviewAlert('une commande a échoué');
		expect($('[data-rail="preview"] .rail-badge').textContent).toBe('!');
	});
});
