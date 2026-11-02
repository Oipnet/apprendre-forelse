// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { suggestDesktop } from '../src/app/mobile.ts';

const telephone = (oui: boolean) => vi.stubGlobal('matchMedia', (query: string) => ({ matches: oui, media: query }));

describe('suggestDesktop', () => {
	beforeEach(() => {
		localStorage.clear();
		document.body.innerHTML = '';
		history.replaceState(null, '', '/bac-a-sable/php?utm_source=linkedin#code=abc');
	});
	afterEach(() => vi.unstubAllGlobals());

	it('sur un téléphone, propose de s\'envoyer le lien, fragment compris', () => {
		telephone(true);
		suggestDesktop(document.body, 'Mon <exercice>', {});

		const dialog = document.querySelector<HTMLDialogElement>('dialog.mobile-desktop')!;
		expect(dialog.open).toBe(true);
		const mailto = dialog.querySelector<HTMLAnchorElement>('[data-choix="email"]')!.getAttribute('href')!;
		expect(mailto).toMatch(/^mailto:\?subject=/);
		const body = decodeURIComponent(mailto.split('&body=')[1]);
		expect(body).toContain('utm_source=rappel');
		expect(body).toContain('utm_campaign=continuer-sur-ordinateur');
		expect(body.endsWith('#code=abc'), 'Le code du bac à sable voyage avec le lien.').toBe(true);
		expect(decodeURIComponent(mailto)).toContain('Mon <exercice>');
	});

	it('« Continuer sur le téléphone » ferme la boîte, qui ne revient plus', () => {
		telephone(true);
		suggestDesktop(document.body, 'T', {});
		document.querySelector<HTMLButtonElement>('[data-choix="continuer"]')!.click();
		expect(document.querySelector('dialog')).toBeNull();

		suggestDesktop(document.body, 'T', {});
		expect(document.querySelector('dialog')).toBeNull();
	});

	it('rien sur un ordinateur', () => {
		telephone(false);
		suggestDesktop(document.body, 'T', {});
		expect(document.querySelector('dialog')).toBeNull();
	});
});
