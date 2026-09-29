// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { confirmDialog, noticeDialog, undoToast } from '../src/app/dialogs.ts';

const cliquer = (label: string) => [...document.querySelectorAll<HTMLButtonElement>('dialog button')].find((b) => b.textContent === label)!.click();

describe('confirmDialog', () => {
	it('vrai quand on confirme, dialogue retiré ensuite', async () => {
		const reponse = confirmDialog(document.body, { title: 'Revenir au <départ> ?', message: 'Vos modifications…', confirm: 'Réinitialiser' });
		const dialog = document.querySelector('dialog')!;
		expect(dialog.open).toBe(true);
		expect(dialog.querySelector('h2')!.textContent).toBe('Revenir au <départ> ?');
		expect(dialog.getAttribute('aria-labelledby')).toBe(dialog.querySelector('h2')!.id);
		expect(document.activeElement?.textContent, 'Le focus sur le bouton prudent.').toBe('Annuler');
		cliquer('Réinitialiser');
		expect(await reponse).toBe(true);
		expect(document.querySelector('dialog')).toBeNull();
	});

	it('faux quand on annule, ou qu\'on ferme sans choisir (Échap)', async () => {
		const annule = confirmDialog(document.body, { title: 'T', message: 'M', confirm: 'OK' });
		cliquer('Annuler');
		expect(await annule).toBe(false);

		const echap = confirmDialog(document.body, { title: 'T', message: 'M', confirm: 'OK' });
		document.querySelector('dialog')!.close();
		expect(await echap).toBe(false);
	});

	it('noticeDialog se ferme sur « Compris »', async () => {
		const lu = noticeDialog(document.body, 'Solution indisponible', 'Serveur absent');
		cliquer('Compris');
		await expect(lu).resolves.toBeUndefined();
	});
});

describe('undoToast', () => {
	beforeEach(() => vi.useFakeTimers());
	afterEach(() => vi.useRealTimers());

	it('propose l\'action quelques secondes, puis disparaît', () => {
		const annuler = vi.fn();
		const toast = undoToast(document.body, 'Code de départ rétabli.', 'Annuler', annuler, 1000);
		expect(toast.getAttribute('role')).toBe('status');
		toast.querySelector('button')!.click();
		expect(annuler).toHaveBeenCalledOnce();
		expect(toast.isConnected).toBe(false);

		const oublie = undoToast(document.body, 'x', 'Annuler', annuler, 1000);
		vi.advanceTimersByTime(1000);
		expect(oublie.isConnected).toBe(false);
		expect(annuler).toHaveBeenCalledOnce();
	});
});
