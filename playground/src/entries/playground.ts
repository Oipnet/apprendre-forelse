// La page d'exercice garde l'habillage d'avant le thème default (voir #235) : site.css, puis le sien.
import '../site.css';
import '../playground.css';
import { mountPlayground } from '../app/Playground';
import type { PlaygroundConfig } from '../app/types';
import { bindRetry, bootFailureActionsHtml } from '../app/boot';

const root = document.querySelector<HTMLElement>('[data-playground]');
// Une erreur pendant la mise en place laissait l'écran de démarrage tourner sans rien dire : elle s'affiche.
if (root) {
	mountPlayground(root, JSON.parse(root.dataset.config ?? '{}') as PlaygroundConfig).catch((error: unknown) => {
		console.error(error);
		// Un échec de démarrage a déjà son message et ses boutons (Playground.ts) : on ne l'écrase pas.
		if (root.dataset.bootFailed !== undefined) return;
		const label = root.querySelector('#boot-label');
		if (!label) return;
		label.textContent = `L'exercice n'a pas pu s'ouvrir : ${error instanceof Error ? error.message : String(error)}`;
		label.insertAdjacentHTML('afterend', bootFailureActionsHtml(false));
		bindRetry(root);
	});
}
