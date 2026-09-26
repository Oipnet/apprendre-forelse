import { describe, expect, it } from 'vitest';
import { ansiToHtml, finalScreen, parseCommandLine } from '../src/app/console.ts';

describe('parseCommandLine', () => {
	it('découpe sur les espaces, en respectant les guillemets simples et doubles', () => {
		expect(parseCommandLine('make:entity Soiree --no-interaction')).toEqual(['make:entity', 'Soiree', '--no-interaction']);
		expect(parseCommandLine(`app:saluer "Ada Lovelace" 'la comtesse'`)).toEqual(['app:saluer', 'Ada Lovelace', 'la comtesse']);
	});

	it('déséchappe les guillemets doubles, pas les simples', () => {
		expect(parseCommandLine(String.raw`echo "il a dit \"bonjour\"" 'a\b'`)).toEqual(['echo', 'il a dit "bonjour"', String.raw`a\b`]);
	});

	it('ignore les espaces en trop et une ligne vide', () => {
		expect(parseCommandLine('   cache:clear    -v  ')).toEqual(['cache:clear', '-v']);
		expect(parseCommandLine('')).toEqual([]);
	});

	it('retire les préfixes tolérés, deux tours au plus (« php bin/console … »)', () => {
		const aliases = { php: '', 'bin/console': '' };
		expect(parseCommandLine('php bin/console cache:clear', aliases)).toEqual(['cache:clear']);
		expect(parseCommandLine('bin/console debug:router', aliases)).toEqual(['debug:router']);
		// Deux tours : un troisième préfixe reste.
		expect(parseCommandLine('php php php about', { php: '' })).toEqual(['php', 'about']);
	});

	it('remplace un préfixe par sa valeur quand elle n\'est pas vide, et s\'arrête là', () => {
		const aliases = { 'docker-compose': 'compose', compose: '' };
		expect(parseCommandLine('docker-compose up -d', aliases)).toEqual(['compose', 'up', '-d']);
	});
});

describe('finalScreen', () => {
	it('retire les séquences OSC (titre, progression)', () => {
		expect(finalScreen('\x1b]0;titre\x07Fini\x1b]9;4;1;50\x1b\\')).toBe('Fini');
	});

	it('ne garde que le dernier état d\'une ligne réécrite', () => {
		expect(finalScreen('10 %\r50 %\r100 %\nsuite')).toBe('100 %\nsuite');
		expect(finalScreen('ancien\x1b[2Knouveau')).toBe('nouveau');
	});

	it('garde un retour chariot de fin de ligne (fins de ligne Windows)', () => {
		expect(finalScreen('ligne\r\nautre')).toBe('ligne\r\nautre');
	});
});

describe('ansiToHtml', () => {
	it('échappe le HTML du texte', () => {
		expect(ansiToHtml('<script>&"')).toBe('&lt;script&gt;&amp;&quot;');
	});

	it('traduit les couleurs, le gras et la remise à zéro en classes', () => {
		expect(ansiToHtml('\x1b[32mOK\x1b[0m fin')).toBe('<span class="fg-2">OK</span> fin');
		expect(ansiToHtml('\x1b[1;37;41m ERREUR \x1b[39;49;22m')).toBe('<span class="fg-7 bg-1 bold"> ERREUR </span>');
		expect(ansiToHtml('\x1b[93mclair')).toBe('<span class="fg-11">clair</span>');
	});

	it('remplace un déplacement du curseur par des espaces, et ignore les autres séquences', () => {
		expect(ansiToHtml('a\x1b[3Cb\x1b[Kc')).toBe('a   bc');
	});

	it('passe d\'abord par finalScreen', () => {
		expect(ansiToHtml('0 %\r\x1b[32m100 %\x1b[0m')).toBe('<span class="fg-2">100 %</span>');
	});
});
