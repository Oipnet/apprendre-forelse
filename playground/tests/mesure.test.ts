// @vitest-environment happy-dom
import { afterEach, describe, expect, it } from 'vitest';
import { mesurer, poserTraceur } from '../src/mesure.ts';

const navigateur = (webdriver: boolean) => ({ webdriver }) as Navigator;
const configuration = '<meta name="mesure-audience" data-src="/mesure/traceur.js" data-website-id="abc">';
const geste = (nom = 'pointermove') => window.dispatchEvent(new Event(nom));

afterEach(() => {
	geste(); // Retire les écouteurs qu'un test aurait laissés, avant de vider la page.
	document.head.innerHTML = '';
	delete (window as { umami?: unknown }).umami;
});

describe('poserTraceur', () => {
	it('pose le traceur avec la configuration de la page au premier geste', () => {
		document.head.innerHTML = configuration;
		const script = poserTraceur(document, navigateur(false))!;
		expect(document.head.querySelector('script')).toBeNull();
		geste('touchstart');
		geste('keydown');
		expect(script.getAttribute('src')).toBe('/mesure/traceur.js');
		expect(script.dataset.websiteId).toBe('abc');
		expect(script.dataset.src).toBeUndefined();
		expect(script.defer).toBe(true);
		expect(document.head.querySelectorAll('script')).toHaveLength(1);
	});

	it('ne compte pas une page affichée sans aucun geste', () => {
		document.head.innerHTML = configuration;
		poserTraceur(document, navigateur(false));
		window.dispatchEvent(new Event('scroll'));
		expect(document.head.querySelector('script')).toBeNull();
	});

	it('ne mesure pas un navigateur piloté par un robot', () => {
		document.head.innerHTML = configuration;
		expect(poserTraceur(document, navigateur(true))).toBeNull();
		geste();
		expect(document.head.querySelector('script')).toBeNull();
	});

	it('envoie les événements annoncés par la page et ceux mesurés avant le chargement du traceur', () => {
		const envoyes: unknown[] = [];
		document.head.innerHTML = configuration + '<meta name="mesure-evenement" content="compte-cree">';
		const script = poserTraceur(document, navigateur(false))!;
		mesurer('exercice-ouvert', { exercice: 'x' });
		geste();
		expect(envoyes).toEqual([]);
		(window as { umami?: unknown }).umami = { track: (nom: string, donnees?: unknown) => envoyes.push([nom, donnees]) };
		script.dispatchEvent(new Event('load'));
		mesurer('tests-lances');
		expect(envoyes).toEqual([
			['compte-cree', undefined],
			['exercice-ouvert', { exercice: 'x' }],
			['tests-lances', undefined],
		]);
	});

	it('ne fait rien sans mesure d\'audience configurée', () => {
		expect(poserTraceur(document, navigateur(false))).toBeNull();
		mesurer('exercice-ouvert');
		expect(document.head.querySelector('script')).toBeNull();
	});
});
