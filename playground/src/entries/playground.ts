import '../playground.css';
import { mountPlayground } from '../app/Playground';
import type { PlaygroundConfig } from '../app/types';

const root = document.querySelector<HTMLElement>('[data-playground]');
// Une erreur pendant la mise en place laissait l'écran de démarrage tourner sans rien dire : elle s'affiche.
if (root) {
	mountPlayground(root, JSON.parse(root.dataset.config ?? '{}') as PlaygroundConfig).catch((error: unknown) => {
		console.error(error);
		const label = root.querySelector('#boot-label');
		if (label) label.textContent = `L'exercice n'a pas pu s'ouvrir : ${error instanceof Error ? error.message : String(error)}`;
	});
}
