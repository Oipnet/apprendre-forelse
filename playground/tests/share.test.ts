// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { mountShare, shareDialog, shareHtml, type ShareLinks } from '../src/app/share.ts';

const links: ShareLinks = {
	text: 'Symfony 8.2 : « <Les sous-commandes> »',
	linkedin: 'https://www.linkedin.com/sharing/share-offsite/?url=a&b',
	x: 'https://x.com/intent/post?text=t',
	bluesky: 'https://bsky.app/intent/compose?text=t',
	lien: 'https://exemple.test/pratique/a?utm_source=lien',
	natif: 'https://exemple.test/pratique/a?utm_source=natif',
};

const monter = () => {
	document.body.innerHTML = shareHtml(links, 'Partager votre <réussite>');
	const root = document.querySelector<HTMLElement>('[data-share]')!;
	mountShare(root, links, { exercice: 'a' });
	return root;
};

afterEach(() => {
	vi.unstubAllGlobals();
	document.body.innerHTML = '';
	delete (window as { umami?: unknown }).umami;
});

describe('shareHtml', () => {
	it('un lien par réseau, ouvert à part, adresses et titre échappés', () => {
		const root = monter();
		expect(root.querySelector('.share-title')!.textContent).toBe('Partager votre <réussite> :');
		const liens = [...root.querySelectorAll<HTMLAnchorElement>('a[data-share-network]')];
		expect(liens.map((a) => a.textContent)).toEqual(['LinkedIn', 'X', 'Bluesky']);
		expect(liens[0].getAttribute('href')).toBe(links.linkedin);
		expect(liens.every((a) => a.target === '_blank' && a.rel === 'noopener noreferrer')).toBe(true);
	});
});

describe('mountShare', () => {
	it('copie le lien et le dit, et mesure le partage', async () => {
		const writeText = vi.fn().mockResolvedValue(undefined);
		vi.stubGlobal('navigator', { clipboard: { writeText } });
		const track = vi.fn();
		(window as { umami?: unknown }).umami = { track };
		const root = monter();

		root.querySelector<HTMLButtonElement>('[data-share-copy]')!.click();
		await vi.waitFor(() => expect(root.querySelector('.share-status')!.textContent).toBe('Lien copié.'));
		expect(writeText).toHaveBeenCalledWith(links.lien);
		expect(track).toHaveBeenCalledWith('partage', { exercice: 'a', reseau: 'lien' });

		// Pas de navigation pendant le test : la mesure part avant.
		root.addEventListener('click', (event) => event.preventDefault());
		root.querySelector<HTMLAnchorElement>('a[data-share-network="bluesky"]')!.dispatchEvent(new MouseEvent('click', { cancelable: true, bubbles: true }));
		expect(track).toHaveBeenCalledWith('partage', { exercice: 'a', reseau: 'bluesky' });
	});

	it('sans presse-papiers, affiche le lien à copier', async () => {
		vi.stubGlobal('navigator', { clipboard: { writeText: vi.fn().mockRejectedValue(new Error('refusé')) } });
		const root = monter();
		root.querySelector<HTMLButtonElement>('[data-share-copy]')!.click();
		await vi.waitFor(() => expect(root.querySelector('.share-status')!.textContent).toBe(links.lien));
	});

	it('le partage du téléphone n\'apparaît que si le navigateur en a un', () => {
		vi.stubGlobal('navigator', {});
		expect(monter().querySelector<HTMLButtonElement>('[data-share-native]')!.hidden).toBe(true);

		const share = vi.fn().mockResolvedValue(undefined);
		vi.stubGlobal('navigator', { share });
		const bouton = monter().querySelector<HTMLButtonElement>('[data-share-native]')!;
		expect(bouton.hidden).toBe(false);
		bouton.click();
		expect(share).toHaveBeenCalledWith({ text: links.text, url: links.natif });
	});
});

describe('shareDialog', () => {
	it('ouvre les liens dans une boîte, retirée à la fermeture', () => {
		shareDialog(document.body, links, 'Partager ce <parcours>');
		const dialog = document.querySelector('dialog')!;
		expect(dialog.open).toBe(true);
		expect(dialog.querySelector('h2')!.textContent).toBe('Partager ce <parcours>');
		expect(dialog.querySelectorAll('a[data-share-network]')).toHaveLength(3);
		dialog.querySelector<HTMLButtonElement>('[data-close]')!.click();
		expect(document.querySelector('dialog')).toBeNull();
	});
});
