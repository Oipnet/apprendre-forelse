// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest';
import { layout } from '../src/app/layout.ts';
import type { ExercisePayload, PlaygroundConfig } from '../src/app/types.ts';

const exercise = {
	title: 'Bac à sable Symfony',
	chapter: null,
	previous: null,
	next: null,
	practice: null,
	concepts: [],
	conceptUrls: {},
	xp: 0,
	instructionsHtml: '<p>Consignes</p>',
	objectives: [],
	docs: [],
	preview: null,
	environment: { framework: { label: 'Symfony', console: 'bin/console', testRunnerLabel: 'PHPUnit' } },
} as unknown as ExercisePayload;
const config = (context: string) => ({ context, brand: { title: 'forelse', name: 'forelse' }, back: { title: 'Bac à sable', url: '/bac-a-sable' } }) as unknown as PlaygroundConfig;

describe('les volets', () => {
	it('au bac à sable, ne garde que le code et l’aperçu', () => {
		const html = layout(exercise, config('scratch'), 'Linux', 'Mozilla/5.0');

		expect(html).toContain('<main class="workspace" data-pane="code" data-no-brief>');
		expect(html).toMatch(/<aside class="panel brief"[^>]* hidden>/);
		expect(html).toContain('<button data-pane="brief" hidden>');
	});

	it('dans un exercice, garde les consignes', () => {
		const html = layout(exercise, config('track'), 'Linux', 'Mozilla/5.0');

		expect(html).not.toContain('data-no-brief');
		expect(html).toContain('<button data-pane="brief">');
	});
});
