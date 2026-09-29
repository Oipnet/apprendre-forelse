// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/** Monaco simulé : `taper()` rejoue ce que l'éditeur signale à chaque frappe. */
const editeur = vi.hoisted(() => ({ onChange: (_chemin: string, _contenu: string) => {} }));
vi.mock('../src/editor/monaco', () => ({
	EditorPanel: class {
		private courant = '';
		constructor(_tabs: HTMLElement, _container: HTMLElement, onChange: (chemin: string, contenu: string) => void) {
			editeur.onChange = onChange;
		}
		addFile() {}
		removeFile() {}
		has() {
			return true;
		}
		open(chemin: string) {
			this.courant = chemin;
		}
		currentPath() {
			return this.courant;
		}
	},
}));

const { mountStudio } = await import('../src/app/Studio.ts');
const { mountLessonStudio } = await import('../src/app/LessonStudio.ts');

/** Chaque fetch attend qu'on lui réponde : l'ordre d'arrivée est celui du test. */
type Appel = { url: string; corps: unknown; repondre: (json: unknown, statut?: number) => Promise<void> };
let appels: Appel[] = [];
const taper = (chemin: string, contenu: string) => editeur.onChange(chemin, contenu);
const statut = () => document.querySelector('.status')!.textContent;
/** Le gestionnaire beforeunload du montage courant (ceux des tests précédents restent posés sur window). */
let avantDeQuitter: ((e: Event) => void) | undefined;
const quitter = () => {
	const evenement = new Event('beforeunload', { cancelable: true });
	avantDeQuitter!(evenement);
	return evenement.defaultPrevented;
};

beforeEach(() => {
	appels = [];
	const ajouter = window.addEventListener.bind(window);
	vi.spyOn(window, 'addEventListener').mockImplementation((type: string, ecouteur: EventListenerOrEventListenerObject, options?: boolean | AddEventListenerOptions) => {
		if (type === 'beforeunload') avantDeQuitter = ecouteur as (e: Event) => void;
		else ajouter(type, ecouteur, options);
	});
	vi.stubGlobal('fetch', (url: string, init?: RequestInit) =>
		new Promise<Response>((resolve) => {
			appels.push({
				url,
				corps: init?.body ? JSON.parse(String(init.body)) : null,
				repondre: async (json, code = 200) => {
					resolve(new Response(JSON.stringify(json), { status: code }));
					await new Promise((r) => setTimeout(r, 0));
				},
			});
		}),
	);
	document.body.innerHTML = `<div id="racine">
		<div class="status"></div><div id="tabs"></div><div id="editor"></div><div id="filetree-body"></div><div id="apercu"></div>
		<div class="actions"><button id="enregistrer"></button><button id="verifier"></button><button id="ajouter"></button>
		<button id="supprimer"></button><button id="supprimer-exercice"></button><button id="squelette"></button><button id="supprimer-fiche"></button></div>
		<div id="verdict" hidden></div></div>`;
});
afterEach(() => {
	vi.unstubAllGlobals();
	vi.restoreAllMocks();
});

const racine = () => document.getElementById('racine')!;
const urls = { enregistrer: '/enregistrer', verifier: '/verifier', corriger: '/corriger', supprimer: '/supprimer', jouer: '/jouer', atelier: '/atelier', apercu: '/apercu', squelette: '/squelette', rediger: '/rediger', lire: '/lire' };

describe('atelier d\'exercice', () => {
	it('une frappe pendant l\'enregistrement reste « non enregistrée »', async () => {
		await mountStudio(racine(), { fichiers: { 'exercise.yaml': 'id: a' }, modifiable: true, ia: false, urls });
		taper('exercise.yaml', 'id: b');
		document.getElementById('enregistrer')!.click();
		taper('exercise.yaml', 'id: c'); // pendant l'envoi

		await appels[0].repondre({ enregistre: true, format: null });

		expect((appels[0].corps as { fichiers: Record<string, string> }).fichiers['exercise.yaml']).toBe('id: b');
		expect(statut()).toBe('Enregistré, mais modifié depuis');
		expect(quitter(), 'Quitter la page prévient toujours.').toBe(true);
	});

	it('un format refusé garde le travail et affiche l\'erreur', async () => {
		await mountStudio(racine(), { fichiers: { 'exercise.yaml': 'id: a' }, modifiable: true, ia: false, urls });
		taper('exercise.yaml', 'concepts: Route');
		document.getElementById('enregistrer')!.click();

		await appels[0].repondre({ erreur: 'Format invalide : rien n\'a été enregistré.', format: '« concepts » est une liste' }, 422);

		expect(statut()).toBe('Format invalide : rien n\'a été enregistré.');
		expect(document.getElementById('verdict')!.textContent).toContain('« concepts » est une liste');
		expect(quitter()).toBe(true);
	});

	it('un enregistrement complet libère la page', async () => {
		await mountStudio(racine(), { fichiers: { 'exercise.yaml': 'id: a' }, modifiable: true, ia: false, urls });
		taper('exercise.yaml', 'id: b');
		document.getElementById('enregistrer')!.click();
		await appels[0].repondre({ enregistre: true, format: null });

		expect(statut()).toBe('Enregistré');
		expect(quitter()).toBe(false);
	});
});

describe('atelier de fiche de cours', () => {
	it('un aperçu arrivé en retard ne remplace pas le plus récent', async () => {
		mountLessonStudio(racine(), { markdown: '# A', modifiable: true, ia: false, urls });
		await appels.shift()!.repondre({ html: '<h1>A</h1>' });
		vi.useFakeTimers();
		taper('lesson.md', '# B');
		vi.advanceTimersByTime(600);
		taper('lesson.md', '# C');
		vi.advanceTimersByTime(600);
		vi.useRealTimers();
		const [ancien, recent] = appels;

		await recent.repondre({ html: '<h1>C</h1>' });
		await ancien.repondre({ html: '<h1>B</h1>' });

		expect(document.getElementById('apercu')!.innerHTML).toBe('<h1>C</h1>');
	});

	it('une frappe pendant l\'enregistrement reste « non enregistrée »', async () => {
		mountLessonStudio(racine(), { markdown: '# A', modifiable: true, ia: false, urls });
		appels.shift(); // aperçu initial
		taper('lesson.md', '# B');
		document.getElementById('enregistrer')!.click();
		const enregistrement = appels.find((a) => a.url === '/enregistrer')!;
		taper('lesson.md', '# C');

		await enregistrement.repondre({ fiche: true });

		expect(statut()).toBe('Enregistré, mais modifié depuis');
		expect(quitter()).toBe(true);
	});
});
