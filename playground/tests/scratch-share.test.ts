// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fingerprint, readPrefs, scratchShareDialog, ShortLinkStore, type ShortLink } from '../src/app/scratch-share.ts';
import type { ShareLinks } from '../src/app/share.ts';

const linksOf = (lien: string): ShareLinks => ({
	text: 'Mon bac à sable',
	linkedin: `https://www.linkedin.com/sharing/share-offsite/?url=${encodeURIComponent(lien)}`,
	x: 'https://x.com/intent/post?text=t',
	bluesky: 'https://bsky.app/intent/compose?text=t',
	lien,
	natif: lien,
});
const LONG = linksOf('https://exemple.test/bac-a-sable/symfony?utm_source=lien#code=abc');
const created = (id: string, expiresAt: string | null = null): ShortLink => ({
	id,
	url: `https://exemple.test/bac-a-sable/s/${id}`,
	expiresAt,
	deleteKey: `cle-${id}`,
	deleteUrl: `/api/bac-a-sable/liens/${id}`,
	share: linksOf(`https://exemple.test/bac-a-sable/s/${id}`),
});
const flush = () => new Promise((resolve) => setTimeout(resolve, 0));
const ouvrir = (shortLinksUrl: string | null = '/api/bac-a-sable/symfony/liens', fragment = 'code=abc') =>
	scratchShareDialog(document.body, { title: 'Partager votre code', fragment, longLinks: LONG, shortLinksUrl, mesure: {}, lang: 'fr' });
const champ = () => document.querySelector<HTMLInputElement>('[data-share-url]')!;

beforeEach(() => localStorage.clear());
afterEach(() => {
	vi.unstubAllGlobals();
	document.body.innerHTML = '';
});

describe('la boîte de partage du bac à sable', () => {
	it('crée un lien court par défaut, sans limite de durée, et le retient pour pouvoir le supprimer', async () => {
		const fetch = vi.fn().mockResolvedValue(new Response(JSON.stringify(created('Ab12Cd34')), { status: 201 }));
		vi.stubGlobal('fetch', fetch);

		ouvrir();
		await flush();

		expect(fetch).toHaveBeenCalledOnce();
		const [url, init] = fetch.mock.calls[0];
		expect(url).toBe('/api/bac-a-sable/symfony/liens');
		expect(JSON.parse(init.body)).toEqual({ code: 'code=abc', duree: 'jamais' });
		expect(document.querySelector<HTMLInputElement>('[data-short]')!.checked).toBe(true);
		expect(champ().value).toBe('https://exemple.test/bac-a-sable/s/Ab12Cd34');
		expect(document.querySelector('[data-short-list]')!.textContent).toContain('exemple.test/bac-a-sable/s/Ab12Cd34');
		expect(document.querySelector('[data-short-list]')!.textContent).toContain('sans limite');
		expect(new ShortLinkStore().list().map((link) => link.id)).toEqual(['Ab12Cd34']);
	});

	it('explique dans une info-bulle que le lien court stocke le code', () => {
		vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('{}', { status: 500 })));
		ouvrir();
		const info = document.querySelector<HTMLButtonElement>('[data-short-info]')!;
		const hint = document.querySelector<HTMLElement>('#share-short-hint')!;

		expect(info.title).toContain('garde votre code sur notre serveur');
		expect(hint.hidden).toBe(true);
		info.click();
		expect(hint.hidden).toBe(false);
		expect(info.getAttribute('aria-expanded')).toBe('true');
	});

	it('reprend le même lien court pour le même code, sans en recréer un', async () => {
		const fetch = vi.fn().mockResolvedValue(new Response(JSON.stringify(created('Ab12Cd34')), { status: 201 }));
		vi.stubGlobal('fetch', fetch);

		ouvrir();
		await flush();
		document.body.innerHTML = '';
		ouvrir();
		await flush();

		expect(fetch).toHaveBeenCalledOnce();
		expect(champ().value).toBe('https://exemple.test/bac-a-sable/s/Ab12Cd34');
	});

	it('décochée, donne le lien long sans rien envoyer, et s’en souvient au partage suivant', async () => {
		const fetch = vi.fn();
		vi.stubGlobal('fetch', fetch);
		localStorage.setItem('forelse:bac-a-sable:partage', JSON.stringify({ short: false, duree: 'jamais' }));

		ouvrir();
		await flush();

		expect(fetch).not.toHaveBeenCalled();
		expect(document.querySelector<HTMLInputElement>('[data-short]')!.checked).toBe(false);
		expect(champ().value).toBe(LONG.lien);
	});

	it('envoie la durée choisie', async () => {
		const fetch = vi.fn().mockResolvedValue(new Response(JSON.stringify(created('Ab12Cd34', '2026-12-03T07:00:00+00:00')), { status: 201 }));
		vi.stubGlobal('fetch', fetch);
		ouvrir();
		await flush();

		const select = document.querySelector<HTMLSelectElement>('[data-duree]')!;
		select.value = '1m';
		select.dispatchEvent(new Event('change'));
		await flush();

		expect(JSON.parse(fetch.mock.calls[1][1].body).duree).toBe('1m');
		expect(readPrefs().duree).toBe('1m');
	});

	it('se rabat sur le lien long si le serveur refuse (limite atteinte, panne)', async () => {
		vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('{}', { status: 429 })));

		ouvrir();
		await flush();

		expect(champ().value).toBe(LONG.lien);
		expect(document.querySelector('[data-short-status]')!.textContent).toContain('Lien court indisponible');
	});

	it('supprime un lien court avec sa clé, et n’en recrée pas un d’office', async () => {
		const fetch = vi
			.fn()
			.mockResolvedValueOnce(new Response(JSON.stringify(created('Ab12Cd34')), { status: 201 }))
			.mockResolvedValueOnce(new Response(null, { status: 204 }));
		vi.stubGlobal('fetch', fetch);
		ouvrir();
		await flush();

		document.querySelector<HTMLButtonElement>('[data-delete="Ab12Cd34"]')!.click();
		await flush();
		await flush();

		const [url, init] = fetch.mock.calls[1];
		expect(url).toBe('/api/bac-a-sable/liens/Ab12Cd34');
		expect(init.method).toBe('DELETE');
		expect(JSON.parse(init.body)).toEqual({ cle: 'cle-Ab12Cd34' });
		expect(new ShortLinkStore().list()).toEqual([]);
		expect(document.querySelector<HTMLInputElement>('[data-short]')!.checked).toBe(false);
		expect(champ().value).toBe(LONG.lien);
		expect(fetch).toHaveBeenCalledTimes(2);
	});

	it('sans API de liens courts, ni case ni liste : le lien long', async () => {
		ouvrir(null);
		await flush();
		expect(document.querySelector('[data-short]')).toBeNull();
		expect(champ().value).toBe(LONG.lien);
	});
});

describe('ShortLinkStore', () => {
	it('oublie les liens expirés et garde ceux sans limite', () => {
		const store = new ShortLinkStore(localStorage, () => Date.parse('2027-01-01T00:00:00Z'));
		store.add(created('Expire01', '2026-12-01T00:00:00+00:00'));
		store.add(created('Valable1', '2027-06-01T00:00:00+00:00'));
		store.add(created('Jamais01'));

		expect(store.list().map((link) => link.id)).toEqual(['Jamais01', 'Valable1']);
	});

	it('distingue deux codes, ou un même code à deux durées', () => {
		expect(fingerprint('jamais\ncode=a')).not.toBe(fingerprint('jamais\ncode=b'));
		expect(fingerprint('jamais\ncode=a')).not.toBe(fingerprint('1m\ncode=a'));
	});
});
