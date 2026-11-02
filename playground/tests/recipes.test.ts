import { strToU8, zipSync } from 'fflate';
import { describe, expect, it } from 'vitest';
import { type CatalogEntry, Packages, parseCatalog, parseInvalidation } from '../src/app/packages.ts';
import { addBundles, addEnvBlock, parseRecipe, type Recipe, recipesReport, removeBundles, removeEnvBlock } from '../src/app/recipes.ts';

const BUNDLES = `<?php

return [
    Symfony\\Bundle\\FrameworkBundle\\FrameworkBundle::class => ['all' => true],
    Symfony\\Bundle\\TwigBundle\\TwigBundle::class => ['all' => true],
];
`;
const ENV = `###> symfony/framework-bundle ###
APP_ENV=dev
###< symfony/framework-bundle ###
`;

const security: Recipe = {
	paquet: 'symfony/security-bundle',
	origine: 'symfony/security-bundle:7.4@github.com/symfony/recipes:main',
	bundles: { 'Symfony\\Bundle\\SecurityBundle\\SecurityBundle': ['all'] },
	fichiers: { 'config/packages/security.yaml': 'security:\n    firewalls: {}\n', 'config/routes/security.yaml': '_security_logout:\n' },
	env: [],
	message: '',
	ignore: [],
};
const mailer: Recipe = { paquet: 'symfony/mailer', origine: 'symfony/mailer:4.3@github.com/symfony/recipes:main', bundles: {}, fichiers: { 'config/packages/mailer.yaml': 'framework:\n    mailer:\n' }, env: ['MAILER_DSN=null://null'], message: 'Configure MAILER_DSN.', ignore: [] };
const fixtures: Recipe = { paquet: 'doctrine/doctrine-fixtures-bundle', origine: 'x', bundles: { 'Doctrine\\Bundle\\FixturesBundle\\DoctrineFixturesBundle': ['dev', 'test'] }, fichiers: {}, env: [], message: '', ignore: [] };

describe('les configurateurs de Flex', () => {
	it('déclare un bundle à la fin de config/bundles.php, une seule fois', () => {
		const apres = addBundles(BUNDLES, { ...security.bundles, ...fixtures.bundles });

		expect(apres).toContain("    Symfony\\Bundle\\SecurityBundle\\SecurityBundle::class => ['all' => true],\n    Doctrine\\Bundle\\FixturesBundle\\DoctrineFixturesBundle::class => ['dev' => true, 'test' => true],\n];");
		expect(addBundles(apres, security.bundles)).toBe(apres);
		expect(removeBundles(apres, Object.keys({ ...security.bundles, ...fixtures.bundles }))).toBe(BUNDLES);
	});

	it('ajoute le bloc du paquet au .env et le retire tel quel', () => {
		const apres = addEnvBlock(ENV, 'symfony/mailer', mailer.env);

		expect(apres).toBe(`${ENV}\n###> symfony/mailer ###\nMAILER_DSN=null://null\n###< symfony/mailer ###\n`);
		expect(addEnvBlock(apres, 'symfony/mailer', ['MAILER_DSN=autre'])).toBe(apres);
		expect(removeEnvBlock(apres, 'symfony/mailer')).toBe(ENV);
	});

	it('écarte d’une recette un chemin qui sortirait du projet et une classe douteuse', () => {
		const recipe = parseRecipe({ ...security, fichiers: { '../.env': 'x', 'config/packages/ok.yaml': 'ok' }, bundles: { 'Evil::class => [], X': ['all'] }, env: ['A=1\nB=2', 'C=3'] });

		expect(recipe?.fichiers).toEqual({ 'config/packages/ok.yaml': 'ok' });
		expect(recipe?.bundles).toEqual({});
		expect(recipe?.env).toEqual(['C=3']);
		expect(parseRecipe({ fichiers: {} })).toBeNull();
	});

	it('écrit ce que Flex affiche après un require', () => {
		expect(recipesReport([security, mailer])).toBe(
			'Symfony operations: 2 recipes\n  - Configuring symfony/security-bundle (symfony/security-bundle:7.4@github.com/symfony/recipes:main)\n  - Configuring symfony/mailer (symfony/mailer:4.3@github.com/symfony/recipes:main)\n\n symfony/mailer  instructions:\n\nConfigure MAILER_DSN.',
		);
		expect(recipesReport([])).toBe('');
	});
});

describe('les paquets d’un projet Symfony', () => {
	const entry = (nom: string, recettes: Recipe[]): CatalogEntry => ({
		nom,
		slug: nom.replace('/', '--'),
		version: '8.1.10',
		description: { fr: '', en: '' },
		dependances: [],
		archive: `symfony-8-doctrine.paquet.${nom.replace('/', '--')}.0123456789ab.zip`,
		poids: 1000,
		recettes,
	});
	const setup = () => {
		const files = new Map<string, string>([
			['config/bundles.php', BUNDLES],
			['.env', ENV],
			['var/cache/dev/App_KernelDevDebugContainer.php.meta', 'meta'],
		]);
		const runtime = {
			writeFiles: async (written: Record<string, string>) => {
				for (const [path, content] of Object.entries(written)) files.set(path, content);
			},
			writeFile: async (path: string, content: string) => void files.set(path, content),
			readFile: async (path: string) => files.get(path) ?? null,
			deleteFile: async (path: string) => void files.delete(path),
		};
		const zip = zipSync({ 'vendor/forelse-paquets/x.php': strToU8('<?php return [];') });
		const catalog = parseCatalog({
			paquets: [entry('symfony/security-bundle', [security]), entry('symfony/mailer', [mailer]), entry('acme/avec-securite', [security, fixtures])],
			invalider: ['var/cache/dev/App_KernelDevDebugContainer.php.meta', '../../etc/passwd'],
		});
		const invalider = parseInvalidation({ invalider: ['var/cache/dev/App_KernelDevDebugContainer.php.meta', '../../etc/passwd', 'var/../x'] });
		const packages = new Packages(catalog, new URL('https://forelse.test/envs/'), runtime, (async () => new Response(zip)) as unknown as typeof fetch, invalider);
		return { files, packages, invalider };
	};

	it('applique la recette : bundle, fichiers, .env, et vide le cache du conteneur', async () => {
		const { files, packages, invalider } = setup();
		files.set('config/packages/security.yaml', 'security: mon réglage\n');
		const changes = await packages.add('symfony/security-bundle');

		expect(invalider).toEqual(['var/cache/dev/App_KernelDevDebugContainer.php.meta']);
		expect(files.get('config/bundles.php')).toContain('SecurityBundle::class');
		expect(files.get('config/packages/security.yaml')).toBe('security: mon réglage\n');
		expect(files.get('config/routes/security.yaml')).toBe('_security_logout:\n');
		expect(files.has('var/cache/dev/App_KernelDevDebugContainer.php.meta')).toBe(false);
		expect(Object.keys(changes.fichiers).sort()).toEqual(['config/bundles.php', 'config/routes/security.yaml']);
		expect(changes.rapport).toContain('Configuring symfony/security-bundle');
	});

	it('défait la recette au retrait, sans toucher à un fichier modifié', async () => {
		const { files, packages } = setup();
		await packages.add('symfony/mailer');
		await packages.add('symfony/security-bundle');
		files.set('config/packages/security.yaml', 'security: modifié\n');
		const changes = await packages.remove('symfony/security-bundle');

		expect(files.get('config/bundles.php')).toBe(BUNDLES);
		expect(files.has('config/routes/security.yaml')).toBe(false);
		expect(files.get('config/packages/security.yaml')).toBe('security: modifié\n');
		expect(changes.supprimes).toEqual(['config/routes/security.yaml']);
		expect(changes.gardes).toEqual(['config/packages/security.yaml']);
		expect(files.get('.env')).toContain('MAILER_DSN=null://null');

		await packages.remove('symfony/mailer');
		expect(files.get('.env')).toBe(ENV);
		expect(files.has('config/packages/mailer.yaml')).toBe(false);
	});

	it('garde la recette d’une dépendance tant qu’un autre paquet ajouté en a besoin', async () => {
		const { files, packages } = setup();
		await packages.add('symfony/security-bundle');
		const second = await packages.add('acme/avec-securite');
		expect(second.rapport).not.toContain('security-bundle');
		expect(files.get('config/bundles.php')).toContain('DoctrineFixturesBundle');

		await packages.remove('symfony/security-bundle');
		expect(files.get('config/bundles.php')).toContain('SecurityBundle::class');
		await packages.remove('acme/avec-securite');
		expect(files.get('config/bundles.php')).toBe(BUNDLES);
	});
});
