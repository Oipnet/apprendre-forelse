// @vitest-environment happy-dom
import { strFromU8, unzipSync } from 'fflate';
import { describe, expect, it } from 'vitest';
import { FileTree } from '../src/app/filetree.ts';
import { ProjectFilesController } from '../src/app/ProjectFilesController.ts';
import type { ExerciseSession } from '../src/app/session.ts';
import type { ExercisePayload } from '../src/app/types.ts';

describe('l’explorateur', () => {
	it('montre le dossier vide mais pas son .gitkeep', () => {
		const racine = document.createElement('div');
		new FileTree(racine, ['src/Entity/.gitkeep', 'src/Kernel.php', 'migrations/.gitkeep'], ['src/*/*.php'], () => {});

		const noms = [...racine.querySelectorAll('summary, button')].map((element) => element.textContent);
		expect(noms).toEqual(['migrations', 'src', 'Entity', 'Kernel.php']);
	});
});

describe('le téléchargement du projet', () => {
	it('emporte le projet visible avec les dernières frappes, sans les tests cachés', async () => {
		const racine = document.createElement('div');
		racine.innerHTML = `<button id="toggle-tree"></button><aside id="filetree"><button id="download-project"></button><button id="new-file"></button><div id="filetree-body"></div></aside><button id="reset"></button>`;
		const disque: Record<string, string> = {
			'composer.json': '{}',
			'src/Controller/AccueilController.php': '<?php // ancien',
			'src/Entity/.gitkeep': '',
			'tests/CacheTest.php': '<?php // la solution',
		};
		const runtime = {
			listFiles: async () => Object.keys(disque),
			readFile: async (path: string) => disque[path] ?? null,
			writeFile: async () => {},
			deleteFile: async () => {},
		};
		const exercise = { id: 'bac-a-sable-symfony', editable: ['src/*/*.php'], tests: { 'tests/CacheTest.php': '' }, files: {}, readonly: [], open: 'src/Controller/AccueilController.php', environment: { framework: {} } } as unknown as ExercisePayload;
		const session = { current: { 'src/Controller/AccueilController.php': '<?php // tapé à l’instant' }, saveDraft() {} } as unknown as ExerciseSession;
		const editor = { addFile() {}, has: () => false, open() {}, setContent() {}, removeFile() {}, currentPath: () => undefined };

		const fichiers = unzipSync(await new ProjectFilesController(racine, editor, runtime, exercise, session, false).archive());

		expect(Object.keys(fichiers)).toEqual(['composer.json', 'src/Controller/AccueilController.php', 'src/Entity/.gitkeep']);
		expect(strFromU8(fichiers['src/Controller/AccueilController.php'])).toBe('<?php // tapé à l’instant');
	});
});
