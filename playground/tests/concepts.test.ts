// @vitest-environment happy-dom
import { beforeEach, describe, expect, it } from 'vitest';
import { mountConcepts } from '../src/app/concepts.ts';

/** L'index tel que concept/index.html.twig le rend, réduit à ce que le module lit. */
const page = `
<main data-concepts>
	<div role="tablist" data-concepts-tabs hidden>
		<button role="tab" aria-controls="concepts-symfony" aria-selected="true">Symfony</button>
		<button role="tab" aria-controls="concepts-laravel" aria-selected="false">Laravel</button>
	</div>
	<section id="concepts-symfony" data-concepts-panel>
		<h2 data-concepts-heading>Symfony</h2>
		<div data-concepts-themes hidden>
			<button data-concepts-theme="all" aria-pressed="true">Tous</button>
			<button data-concepts-theme="doctrine" aria-pressed="false">Doctrine</button>
		</div>
		<section data-concepts-group="securite"><ul><li>Voter</li><li>Hachage</li></ul></section>
		<section data-concepts-group="doctrine"><ul><li>Requêtes N+1</li></ul></section>
	</section>
	<section id="concepts-laravel" data-concepts-panel>
		<h2 data-concepts-heading>Laravel</h2>
		<section data-concepts-group="autres"><ul><li>Collections</li></ul></section>
	</section>
</main>`;

const $ = <T extends HTMLElement>(selector: string) => document.querySelector<T>(selector)!;

describe('mountConcepts', () => {
	beforeEach(() => {
		document.body.innerHTML = page;
		mountConcepts($('[data-concepts]'));
	});

	it('fait des frameworks des onglets : un seul panneau visible, les titres redondants masqués', () => {
		expect($('[data-concepts-tabs]').hidden).toBe(false);
		expect($('#concepts-symfony').hidden).toBe(false);
		expect($('#concepts-laravel').hidden).toBe(true);
		expect($('[data-concepts-heading]').hidden).toBe(true);

		$('[aria-controls="concepts-laravel"]').click();

		expect($('#concepts-symfony').hidden).toBe(true);
		expect($('#concepts-laravel').hidden).toBe(false);
		expect($('[aria-controls="concepts-laravel"]').getAttribute('aria-selected')).toBe('true');
	});

	it('filtre par thème', () => {
		$('[data-concepts-theme="doctrine"]').click();

		expect($('[data-concepts-group="securite"]').hidden).toBe(true);
		expect($('[data-concepts-group="doctrine"]').hidden).toBe(false);
		expect($('[data-concepts-theme="doctrine"]').getAttribute('aria-pressed')).toBe('true');
	});
});
