// @vitest-environment happy-dom
import { afterEach, describe, expect, it } from 'vitest';
import { poserTraceur } from '../src/mesure.ts';

const navigateur = (webdriver: boolean) => ({ webdriver }) as Navigator;

afterEach(() => {
	document.head.innerHTML = '';
});

describe('poserTraceur', () => {
	it('pose le traceur avec la configuration de la page', () => {
		document.head.innerHTML = '<meta name="mesure-audience" data-src="/mesure/traceur.js" data-website-id="abc">';
		const script = poserTraceur(document, navigateur(false))!;
		expect(script.getAttribute('src')).toBe('/mesure/traceur.js');
		expect(script.dataset.websiteId).toBe('abc');
		expect(script.dataset.src).toBeUndefined();
		expect(script.defer).toBe(true);
		expect(document.head.querySelectorAll('script')).toHaveLength(1);
	});

	it('ne mesure pas un navigateur piloté par un robot', () => {
		document.head.innerHTML = '<meta name="mesure-audience" data-src="/mesure/traceur.js" data-website-id="abc">';
		expect(poserTraceur(document, navigateur(true))).toBeNull();
		expect(document.head.querySelector('script')).toBeNull();
	});

	it('envoie les événements annoncés par la page une fois le traceur chargé', () => {
		const envoyes: string[] = [];
		document.head.innerHTML = '<meta name="mesure-audience" data-src="/mesure/traceur.js" data-website-id="abc"><meta name="mesure-evenement" content="compte-cree">';
		const script = poserTraceur(document, navigateur(false))!;
		expect(envoyes).toEqual([]);
		(window as { umami?: unknown }).umami = { track: (nom: string) => envoyes.push(nom) };
		script.dispatchEvent(new Event('load'));
		expect(envoyes).toEqual(['compte-cree']);
		delete (window as { umami?: unknown }).umami;
	});

	it('ne fait rien sans mesure d\'audience configurée', () => {
		expect(poserTraceur(document, navigateur(false))).toBeNull();
		expect(document.head.querySelector('script')).toBeNull();
	});
});
