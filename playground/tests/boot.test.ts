import { describe, expect, it } from 'vitest';
import { bootFailureActionsHtml, firstLoadNote, loadErrorHtml } from '../src/app/boot.ts';

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
