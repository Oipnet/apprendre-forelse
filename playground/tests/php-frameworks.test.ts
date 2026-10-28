/**
 * Le choix du script de console d'un framework PHP : la table, la console de Symfony pour un profil inconnu qui a
 * bin/console, une erreur claire sinon.
 */
import { describe, expect, it } from 'vitest';
import { defaultPhpFramework, PHP_FRAMEWORKS, phpFramework } from '../src/runtime/php-frameworks.ts';

describe('phpFramework', () => {
	it('prend l\'adaptateur de la table', () => {
		expect(phpFramework(PHP_FRAMEWORKS, { id: 'laravel', console: 'artisan' })).toBe(PHP_FRAMEWORKS.laravel);
	});

	it('lance un script PHP pour un projet sans framework', () => {
		expect(phpFramework(PHP_FRAMEWORKS, { id: 'php', console: 'php' })().consoleScript).toContain('Could not open input file');
	});

	it('joue la console de Symfony pour un profil inconnu dont la console est bin/console', () => {
		expect(phpFramework(PHP_FRAMEWORKS, { id: 'sylius', console: 'bin/console' })).toBe(defaultPhpFramework);
	});

	it('refuse un profil inconnu à la console différente, en le nommant', () => {
		expect(() => phpFramework(PHP_FRAMEWORKS, { id: 'cakephp', console: 'bin/cake' })).toThrow(/cakephp.*bin\/cake/);
	});
});
