import { describe, expect, it } from 'vitest';
import { alreadyDoneHtml, gainText, lessonLinkHtml, nextStepHtml } from '../src/app/SuccessPanel.ts';

const suite = { id: '02', title: 'Les <routes>', url: '/parcours/symfony/02' };
const exercice = { next: null, nextTrack: null, practice: null, lesson: null };
const parcours = { context: 'track' as const, progress: { mode: 'api' as const, url: '/api' }, back: { title: 'Symfony', url: '/parcours/symfony' }, registerUrl: null, waitlistUrl: null };

describe('nextStepHtml', () => {
	it('propose l\'exercice suivant, titre échappé', () => {
		expect(nextStepHtml({ ...exercice, next: suite }, parcours)).toBe('<a class="button primary" href="/parcours/symfony/02">Exercice suivant : Les &lt;routes&gt; →</a>');
	});

	it('invite l\'invité à créer un compte, ou à rejoindre la liste d\'attente en bêta fermée', () => {
		const invite = { ...parcours, progress: { mode: 'local' as const }, registerUrl: '/inscription' };
		expect(nextStepHtml({ ...exercice, next: suite }, invite)).toContain('<a class="button primary" href="/inscription">Créer mon compte</a>');
		const beta = nextStepHtml({ ...exercice, next: suite }, { ...invite, waitlistUrl: '/attente' });
		expect(beta).toContain('J\'ai un code d\'invitation');
		expect(beta).toContain('href="/attente"');
	});

	it('au dernier exercice, propose le parcours conseillé ensuite, sinon le retour au parcours', () => {
		const html = nextStepHtml({ ...exercice, nextTrack: { id: 'laravel', title: 'Laravel', description: 'Un autre framework', url: '/parcours/laravel' } }, parcours);
		expect(html).toContain('Continuez avec <strong>Laravel</strong> : Un autre framework</p>');
		expect(html).toContain('href="/parcours/symfony">Retour au parcours</a>');
		expect(nextStepHtml(exercice, parcours)).toBe('<p>C\'était le dernier exercice de « Symfony ».</p><a class="button" href="/parcours/symfony">Retour au parcours</a>');
	});

	it('en Pratique, ramène à la Pratique, avec la pull request d\'origine si elle est connue', () => {
		const pratique = { ...parcours, context: 'practice' as const, back: { title: 'Pratique', url: '/pratique' } };
		expect(nextStepHtml(exercice, pratique)).toBe('<a class="button" href="/pratique">Retour à la Pratique</a>');
		const practice = { framework: 'symfony', version: '8.1', versionUrl: null, pullRequest: 'https://github.com/symfony/symfony/pull/1', published: '2026-01-01' };
		expect(nextStepHtml({ ...exercice, next: suite, practice }, pratique)).toContain('href="https://github.com/symfony/symfony/pull/1"');
	});
});

describe('la réussite, en mots', () => {
	it('annonce l\'XP gagnée et le total, sauf en Pratique', () => {
		expect(gainText({ xpEarned: 30, totalXp: 130, alreadyCompleted: false }, parcours, false)).toBe('+30 XP · 130 XP au total');
		expect(gainText({ xpEarned: 0, totalXp: 100, alreadyCompleted: false }, parcours, true)).toBe('Sans XP : la solution a été consultée. · 100 XP au total');
		expect(gainText({ xpEarned: 0, totalXp: null, alreadyCompleted: true }, parcours, false)).toBe('Exercice déjà validé.');
		expect(gainText({ xpEarned: 0, totalXp: 100, alreadyCompleted: false }, { context: 'practice' }, false)).toBe('');
	});

	it('rappelle la suite au retour, et la fiche de cours aux comptes seulement', () => {
		expect(alreadyDoneHtml({ next: suite, nextTrack: null }, parcours)).toContain('Exercice suivant : Les &lt;routes&gt;');
		expect(alreadyDoneHtml({ next: suite, nextTrack: null }, { context: 'practice' })).toBe('✓ Vous avez déjà réussi cet exercice.');
		const lesson = { title: 'Routes', url: '/cours/routes' };
		expect(lessonLinkHtml({ lesson }, parcours, ' ')).toBe(' <a class="lesson-unlocked" href="/cours/routes">📜 Fiche de cours du chapitre « Routes » →</a>');
		expect(lessonLinkHtml({ lesson }, { progress: { mode: 'local' } })).toBe('');
	});
});
