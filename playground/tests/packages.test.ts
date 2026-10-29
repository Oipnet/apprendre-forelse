import { strToU8, zipSync } from 'fflate';
import { describe, expect, it, vi } from 'vitest';
import { archiveFiles, type CatalogEntry, knownPackages, Packages, parseCatalog, readSelection, tablePath, writeSelection } from '../src/app/packages.ts';

const carbon: CatalogEntry = {
	nom: 'nesbot/carbon',
	slug: 'nesbot--carbon',
	version: '3.15.1',
	description: { fr: 'Dates', en: 'Dates' },
	dependances: ['symfony/translation'],
	archive: 'php-8.paquet.nesbot--carbon.0123456789ab.zip',
	poids: 420_000,
};
const uuid: CatalogEntry = { ...carbon, nom: 'ramsey/uuid', slug: 'ramsey--uuid', archive: 'php-8.paquet.ramsey--uuid.ba9876543210.zip' };

describe('le catalogue', () => {
	it('écarte une entrée incomplète ou dont l’archive sortirait de /envs', () => {
		const catalog = parseCatalog({ paquets: [carbon, { ...uuid, archive: '../secret.zip' }, { ...uuid, nom: 'pas un paquet' }, 'x'] });

		expect(catalog.map((entry) => entry.nom)).toEqual(['nesbot/carbon']);
		expect(parseCatalog(null)).toEqual([]);
	});

	it('ne retient d’un lien que les paquets du catalogue, dans son ordre', () => {
		expect(knownPackages(['ramsey/uuid', 'evil/package', 42, 'nesbot/carbon', 'ramsey/uuid'], [carbon, uuid])).toEqual(['nesbot/carbon', 'ramsey/uuid']);
	});

	it('retient le choix par projet, et survit à un stockage abîmé ou absent', () => {
		const store = new Map<string, string>();
		const storage = { getItem: (k: string) => store.get(k) ?? null, setItem: (k: string, v: string) => void store.set(k, v) };

		writeSelection(storage, 'bac-a-sable-php', ['nesbot/carbon']);
		expect(readSelection(storage, 'bac-a-sable-php')).toEqual(['nesbot/carbon']);
		store.set('forelse:bac-a-sable:paquets:bac-a-sable-php', '{');
		expect(readSelection(storage, 'bac-a-sable-php')).toEqual([]);
		expect(readSelection(null, 'bac-a-sable-php')).toEqual([]);
	});
});

describe('une archive de paquet', () => {
	it('ne donne que des fichiers sous vendor/', () => {
		const zip = zipSync({ 'vendor/nesbot/carbon/src/Carbon.php': strToU8('<?php // été'), 'vendor/../index.php': strToU8('x'), 'public/index.php': strToU8('x'), 'vendor/dossier/': new Uint8Array() });

		expect(archiveFiles(zip)).toEqual({ 'vendor/nesbot/carbon/src/Carbon.php': '<?php // été' });
	});
});

describe('Packages', () => {
	const zip = zipSync({ 'vendor/nesbot/carbon/src/Carbon.php': strToU8('<?php'), [tablePath(carbon)]: strToU8('<?php return [];') });
	const setup = () => {
		const runtime = { writeFiles: vi.fn(async () => {}), deleteFile: vi.fn(async () => {}) };
		const fetcher = vi.fn(async (_url: URL) => new Response(zip));
		return { runtime, fetcher, packages: new Packages([carbon, uuid], new URL('https://forelse.test/envs/php-8.paquets.json?v=1'), runtime, fetcher as unknown as typeof fetch) };
	};

	it('télécharge l’archive à côté du catalogue et l’écrit dans le projet', async () => {
		const { runtime, fetcher, packages } = setup();
		await packages.add('nesbot/carbon');

		expect(String(fetcher.mock.calls[0][0])).toBe('https://forelse.test/envs/php-8.paquet.nesbot--carbon.0123456789ab.zip');
		expect(runtime.writeFiles).toHaveBeenCalledWith({ 'vendor/nesbot/carbon/src/Carbon.php': '<?php', 'vendor/forelse-paquets/nesbot--carbon.php': '<?php return [];' });
		expect(packages.names).toEqual(['nesbot/carbon']);
	});

	it('retire un paquet en effaçant sa table, et le rajoute sans retélécharger', async () => {
		const { runtime, fetcher, packages } = setup();
		await packages.add('nesbot/carbon');
		await packages.remove('nesbot/carbon');

		expect(runtime.deleteFile).toHaveBeenCalledWith('vendor/forelse-paquets/nesbot--carbon.php');
		expect(packages.names).toEqual([]);
		await packages.add('nesbot/carbon');
		expect(fetcher).toHaveBeenCalledTimes(1);
	});

	it('ignore un paquet hors catalogue, et dit un téléchargement refusé', async () => {
		const { runtime, packages } = setup();
		await packages.add('evil/package');
		expect(runtime.writeFiles).not.toHaveBeenCalled();

		const refuse = new Packages([carbon], new URL('https://forelse.test/envs/'), runtime, (async () => new Response('', { status: 404 })) as unknown as typeof fetch);
		await expect(refuse.add('nesbot/carbon')).rejects.toThrow(/404/);
		expect(refuse.names).toEqual([]);
	});
});
