import { describe, expect, it } from 'vitest';
import { crumbs } from '../src/app/layout.ts';

const chapitre = { title: 'Les <routes>', number: 2, position: 3, total: 5, url: '/parcours/symfony/chapitre/routes/sommaire' };
const voisin = (id: string) => ({ id, title: `Exercice ${id}`, url: `/parcours/symfony/${id}` });

describe('crumbs', () => {
	it('dit le chapitre, la place de l\'exercice et mène à ses voisins', () => {
		const html = crumbs({ title: 'Le menu', chapter: chapitre, previous: voisin('02'), next: voisin('04') });

		expect(html).toContain('href="/parcours/symfony/chapitre/routes/sommaire" title="Chapitre 2 : Les &lt;routes&gt;">Ch. 2 · Les &lt;routes&gt;</a>');
		expect(html).toContain('<span aria-hidden="true">3/5</span><span class="sr-only">Exercice 3 sur 5 du chapitre</span>');
		expect(html).toContain('rel="prev" href="/parcours/symfony/02"');
		expect(html).toContain('rel="next" href="/parcours/symfony/04"');
	});

	it('au premier exercice, la flèche « précédent » est inerte', () => {
		const html = crumbs({ title: 'Le menu', chapter: chapitre, previous: null, next: voisin('02') });
		expect(html).not.toContain('rel="prev"');
		expect(html).toContain('<span class="crumb-arrow" aria-hidden="true">←</span>');
	});

	it('en Pratique : le titre seul, sans chapitre ni voisins', () => {
		expect(crumbs({ title: 'Un <point>', chapter: null, previous: null, next: null })).toBe('<span class="crumb-current">Un &lt;point&gt;</span>');
	});
});
