import { describe, expect, it } from 'vitest';
import { furtherHtml } from '../src/app/layout.ts';
import type { ExercisePayload } from '../src/app/types.ts';

const environment = { framework: { label: 'Symfony' } } as ExercisePayload['environment'];
const back = { title: 'Pratique', url: '/pratique' };
const practice: NonNullable<ExercisePayload['practice']> = {
	framework: 'symfony',
	version: '8.2',
	summary: 'Un résumé.',
	versionUrl: '/pratique/nouveautes/symfony-8-2',
	pullRequest: null,
	published: '2026-10-01',
	track: { id: 'symfony', title: 'Symfony <pour> les devs PHP', description: '', url: '/parcours/symfony' },
};

describe('furtherHtml', () => {
	it('en Pratique, mène aux nouveautés de la version, au parcours et à toute la Pratique', () => {
		const html = furtherHtml({ practice, environment }, { back });

		expect(html).toContain('<a href="/pratique/nouveautes/symfony-8-2">Les autres nouveautés de Symfony 8.2</a>');
		expect(html).toContain('<a href="/parcours/symfony">Le parcours Symfony &lt;pour&gt; les devs PHP</a>');
		expect(html).toContain('<a href="/pratique">Tous les exercices de la Pratique</a>');
	});

	it('sans page de version ni parcours, garde le lien vers la Pratique', () => {
		const html = furtherHtml({ practice: { ...practice, versionUrl: null, track: null }, environment }, { back });

		expect(html).not.toContain('nouveautés');
		expect(html).not.toContain('Le parcours');
		expect(html).toContain('Tous les exercices de la Pratique');
	});

	it('dans un parcours, n\'ajoute rien', () => {
		expect(furtherHtml({ practice: null, environment }, { back })).toBe('');
	});

	it('au bac à sable, mène aux liens donnés par la plateforme, échappés', () => {
		const html = furtherHtml({ practice: null, environment }, { back, scratch: { links: [{ title: 'Le bac à sable <Laravel>', url: '/bac-a-sable/laravel' }] } });

		expect(html).toContain('<a href="/bac-a-sable/laravel">Le bac à sable &lt;Laravel&gt;</a>');
		expect(html).not.toContain('/pratique');
	});
});
