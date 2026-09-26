import { describe, expect, it } from 'vitest';
import { cheminsExplicites, estModifiable } from '../src/app/editable.ts';

describe('estModifiable', () => {
	const editable = ['src/Controller/MenuController.php', 'migrations/*.php', 'templates/menu/*.html.twig'];

	it('accepte un chemin nommé tel quel', () => {
		expect(estModifiable('src/Controller/MenuController.php', editable)).toBe(true);
		expect(estModifiable('src/Controller/AutreController.php', editable)).toBe(false);
	});

	it('accepte les fichiers couverts par un motif, y compris ceux que la console va créer', () => {
		expect(estModifiable('migrations/Version20260926120000.php', editable)).toBe(true);
		expect(estModifiable('templates/menu/index.html.twig', editable)).toBe(true);
	});

	it('« * » ne franchit pas un « / », comme côté plateforme', () => {
		expect(estModifiable('migrations/archives/Version1.php', editable)).toBe(false);
		expect(estModifiable('templates/menu/plats/index.html.twig', editable)).toBe(false);
	});

	it('prend les caractères spéciaux d\'un motif au pied de la lettre', () => {
		expect(estModifiable('src/a.php', ['src/*.php'])).toBe(true);
		expect(estModifiable('src/aXphp', ['src/*.php'])).toBe(false);
	});

	it('ne garde que les chemins explicites', () => {
		expect(cheminsExplicites(editable)).toEqual(['src/Controller/MenuController.php']);
	});
});
