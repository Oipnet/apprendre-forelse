// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { RUNS, ScratchOfferBanner } from '../src/app/scratch-offer.ts';

const offer = {
	track: 'apprendre-php-8',
	title: 'Vous aimez essayer du PHP ici ?',
	text: 'Le parcours « Apprendre PHP 8 » vous guide <pas à pas>.',
	price: '19,00 € TTC, accès à vie.',
	cta: 'Voir le parcours',
	url: '/parcours/apprendre-php-8',
	dismiss: 'Ne plus afficher',
};

describe('ScratchOfferBanner', () => {
	let track: ReturnType<typeof vi.fn>;
	beforeEach(() => {
		localStorage.clear();
		document.body.innerHTML = '<div class="pane-preview"></div>';
		track = vi.fn();
		vi.stubGlobal('umami', { track });
	});
	afterEach(() => vi.unstubAllGlobals());

	const host = () => document.querySelector<HTMLElement>('.pane-preview')!;
	const run = (banner: ScratchOfferBanner, status = 200) => {
		banner.ran();
		banner.response(status);
	};

	it(`n'apparaît qu'à la ${RUNS}e exécution réussie, jamais au premier clic`, () => {
		const banner = new ScratchOfferBanner(host(), offer, { parcours: 'bac-a-sable' });
		run(banner);
		expect(document.querySelector('.scratch-offer')).toBeNull();
		for (let i = 1; i < RUNS; i++) run(banner);

		const aside = document.querySelector('.scratch-offer')!;
		expect(aside.querySelector('a')!.getAttribute('href')).toBe('/parcours/apprendre-php-8');
		expect(aside.textContent).toContain('19,00 €');
		expect(aside.innerHTML, 'Le texte du serveur est échappé.').toContain('&lt;pas à pas&gt;');
		expect(track).toHaveBeenCalledWith('bac-a-sable-offre', { parcours: 'apprendre-php-8', action: 'vue' });
	});

	it('ne compte ni les erreurs ni les réponses sans clic sur « Exécuter »', () => {
		const banner = new ScratchOfferBanner(host(), offer, {});
		for (let i = 0; i < RUNS; i++) run(banner, 500);
		for (let i = 0; i < RUNS; i++) banner.response(200); // frappes, ressources de la page
		expect(document.querySelector('.scratch-offer')).toBeNull();
	});

	it('une seule fois par page', () => {
		const banner = new ScratchOfferBanner(host(), offer, {});
		for (let i = 0; i < RUNS + 3; i++) run(banner);
		expect(document.querySelectorAll('.scratch-offer')).toHaveLength(1);
	});

	it('mesure le clic sur le lien', () => {
		const banner = new ScratchOfferBanner(host(), offer, {});
		for (let i = 0; i < RUNS; i++) run(banner);
		const link = document.querySelector<HTMLAnchorElement>('.scratch-offer a')!;
		link.addEventListener('click', (e) => e.preventDefault());
		link.click();
		expect(track).toHaveBeenCalledWith('bac-a-sable-offre', { parcours: 'apprendre-php-8', action: 'clic' });
	});

	it('la croix le ferme pour de bon', () => {
		const banner = new ScratchOfferBanner(host(), offer, {});
		for (let i = 0; i < RUNS; i++) run(banner);
		document.querySelector<HTMLButtonElement>('.scratch-offer [data-close]')!.click();
		expect(document.querySelector('.scratch-offer')).toBeNull();
		expect(track).toHaveBeenCalledWith('bac-a-sable-offre', { parcours: 'apprendre-php-8', action: 'fermee' });

		const again = new ScratchOfferBanner(host(), offer, {});
		for (let i = 0; i < RUNS; i++) run(again);
		expect(document.querySelector('.scratch-offer')).toBeNull();
	});
});
