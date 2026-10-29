import { deflateSync, strToU8 } from 'fflate';
import { describe, expect, it } from 'vitest';
import { changedFiles, decodeFragment, encodeFragment, withFragment } from '../src/app/scratch.ts';
import type { ShareLinks } from '../src/app/share.ts';

/** Un fragment fabriqué à la main, pour les cas qu'encodeFragment ne produit pas. */
const fragmentOf = (data: unknown) => {
	const bytes = deflateSync(strToU8(JSON.stringify(data)));
	return 'code=' + btoa(String.fromCharCode(...bytes)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
};

describe('le code dans l’adresse', () => {
	it('fait l’aller-retour, accents et caractères spéciaux compris, en restant lisible dans une URL', () => {
		const files = { 'src/Controller/AccueilController.php': "<?php\n// Été : « ça marche » ✓ +/=\n", 'templates/accueil.html.twig': '{{ titre }}' };
		const fragment = encodeFragment(files);

		expect(fragment).toMatch(/^code=[A-Za-z0-9_-]+$/);
		expect(decodeFragment(`#${fragment}`)).toEqual({ files, packages: [] });
	});

	it('porte aussi les paquets ajoutés, et un lien sans paquet reste celui d’avant', () => {
		const files = { 'public/index.php': '<?php echo 1;' };

		expect(decodeFragment(encodeFragment(files, ['nesbot/carbon']))).toEqual({ files, packages: ['nesbot/carbon'] });
		expect(encodeFragment(files, [])).toBe(encodeFragment(files));
		expect(decodeFragment(fragmentOf({ v: 1, files, packages: ['a/b', 42] }))?.packages).toEqual(['a/b']);
	});

	it('ne garde que les fichiers modifiés ou créés depuis le départ', () => {
		expect(changedFiles({ a: 'départ', b: 'modifié', c: 'nouveau' }, { a: 'départ', b: 'avant' })).toEqual({ b: 'modifié', c: 'nouveau' });
	});

	it('ignore une adresse sans code, ou un code illisible', () => {
		expect(decodeFragment('')).toBeNull();
		expect(decodeFragment('#demande')).toBeNull();
		expect(decodeFragment('#code=pas-du-deflate')).toBeNull();
		expect(decodeFragment(`#${fragmentOf({ v: 2, files: {} })}`)).toBeNull();
	});

	it('écarte les chemins qui sortent du projet et les contenus qui ne sont pas du texte', () => {
		const fragment = fragmentOf({ v: 1, files: { '../etc/passwd': 'x', '/racine.php': 'x', 'src/../Kernel.php': 'x', 'src/A.php': 42, 'src/B.php': 'ok' } });

		expect(decodeFragment(fragment)?.files).toEqual({ 'src/B.php': 'ok' });
	});

	it('refuse un code qui gonfle trop une fois décompressé', () => {
		expect(decodeFragment(fragmentOf({ v: 1, files: { 'src/A.php': 'a'.repeat(2_000_000) } }))).toBeNull();
	});
});

describe('withFragment', () => {
	const links: ShareLinks = {
		text: 'Mon code Symfony',
		linkedin: 'https://www.linkedin.com/sharing/share-offsite/?url=https%3A%2F%2Fex.test%2Fbac-a-sable%2Fsymfony%3Futm_source%3Dlinkedin',
		x: 'https://x.com/intent/post?text=Mon%20code&url=https%3A%2F%2Fex.test%2Fbac-a-sable%2Fsymfony%3Futm_source%3Dx',
		bluesky: 'https://bsky.app/intent/compose?text=Mon%20code%20https%3A%2F%2Fex.test%2Fbac-a-sable%2Fsymfony%3Futm_source%3Dbluesky',
		lien: 'https://ex.test/bac-a-sable/symfony?utm_source=lien',
		natif: 'https://ex.test/bac-a-sable/symfony?utm_source=natif',
	};

	it('ajoute le code à chaque adresse partagée', () => {
		const shared = withFragment(links, 'code=abc');

		expect(shared.lien).toBe('https://ex.test/bac-a-sable/symfony?utm_source=lien#code=abc');
		expect(shared.natif).toBe('https://ex.test/bac-a-sable/symfony?utm_source=natif#code=abc');
		expect(new URL(shared.linkedin).searchParams.get('url')).toBe('https://ex.test/bac-a-sable/symfony?utm_source=linkedin#code=abc');
		expect(new URL(shared.x).searchParams.get('url')).toBe('https://ex.test/bac-a-sable/symfony?utm_source=x#code=abc');
		expect(new URL(shared.bluesky).searchParams.get('text')).toBe('Mon code https://ex.test/bac-a-sable/symfony?utm_source=bluesky#code=abc');
	});

	it('retire Bluesky quand l’adresse ne tient plus dans un post', () => {
		expect(withFragment(links, 'code=' + 'a'.repeat(400)).bluesky).toBe('');
	});
});
