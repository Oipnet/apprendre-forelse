// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { bootFailureActionsHtml, firstLoadNote, loadErrorHtml, suivreDemarrage } from '../src/app/boot.ts';

describe('firstLoadNote', () => {
	const archive = 'https://exemple.test/envs/symfony-8.zip?v=2';

	it('prévient au premier téléchargement, et à chaque nouvelle version de l\'archive', () => {
		expect(firstLoadNote(null, archive)).toContain('Premier chargement');
		expect(firstLoadNote('https://exemple.test/envs/symfony-8.zip?v=1', archive)).toContain('plus vite');
	});

	it('se tait quand l\'archive est déjà dans le cache', () => {
		expect(firstLoadNote(archive, archive)).toBeNull();
	});
});

describe('loadErrorHtml', () => {
	it('session expirée : propose de se reconnecter', () => {
		const html = loadErrorHtml(401, '/connexion?suite=/pratique/x');
		expect(html).toContain('Votre session a expiré.');
		expect(html).toContain('href="/connexion?suite=/pratique/x">Se reconnecter</a>');
	});

	it('accès terminé, exercice introuvable : pas de « Réessayer », qui n\'y changerait rien', () => {
		expect(loadErrorHtml(403)).toContain('ne vous est plus accessible');
		expect(loadErrorHtml(404)).toContain('introuvable');
		expect(loadErrorHtml(404)).not.toContain('data-retry');
	});

	it('serveur en erreur ou connexion coupée : on peut réessayer', () => {
		expect(loadErrorHtml(503)).toContain('(erreur 503)');
		expect(loadErrorHtml(null)).toContain('La connexion semble coupée.');
		expect(loadErrorHtml(null)).toContain('data-retry');
		expect(loadErrorHtml(500)).toContain('role="alert"');
	});
});

describe('bootFailureActionsHtml', () => {
	it('propose de réessayer, et de lire la consigne quand la page est montée', () => {
		expect(bootFailureActionsHtml()).toContain('data-read-brief');
		expect(bootFailureActionsHtml(false)).not.toContain('data-read-brief');
	});
});

describe('suivreDemarrage', () => {
	const mesure = { exercice: 'url-signee', parcours: 'pratique' };
	let track: ReturnType<typeof vi.fn>;
	let visibilite: DocumentVisibilityState;

	beforeEach(() => {
		track = vi.fn();
		(window as { umami?: unknown }).umami = { track };
		visibilite = 'visible';
		vi.spyOn(document, 'visibilityState', 'get').mockImplementation(() => visibilite);
	});
	afterEach(() => {
		vi.restoreAllMocks();
		delete (window as { umami?: unknown }).umami;
	});
	const cacher = () => {
		visibilite = 'hidden';
		document.dispatchEvent(new Event('visibilitychange'));
	};

	it('prêt : exercice-ouvert avec la durée en secondes et le premier chargement', () => {
		const debut = performance.now() - 12_345;
		suivreDemarrage(mesure, true, debut).pret({ compte: false });
		expect(track).toHaveBeenCalledWith('exercice-ouvert', { ...mesure, premier: true, duree: expect.closeTo(12.3, 0) as number, compte: false });
	});

	it('échec : demarrage-echoue, et plus rien quand la page se cache ensuite', () => {
		suivreDemarrage(mesure, false).echec();
		cacher();
		expect(track).toHaveBeenCalledTimes(1);
		expect(track).toHaveBeenCalledWith('demarrage-echoue', expect.objectContaining({ ...mesure, premier: false }));
	});

	it('page quittée avant d\'être prête : demarrage-interrompu une fois, et l\'ouverture compte au retour', () => {
		const suivi = suivreDemarrage(mesure, true);
		cacher();
		cacher();
		visibilite = 'visible';
		suivi.pret();
		expect(track.mock.calls.map(([nom]) => nom)).toEqual(['demarrage-interrompu', 'exercice-ouvert']);
	});

	it('page cachée après le démarrage : rien de plus', () => {
		suivreDemarrage(mesure, false).pret();
		cacher();
		expect(track.mock.calls.map(([nom]) => nom)).toEqual(['exercice-ouvert']);
	});
});
