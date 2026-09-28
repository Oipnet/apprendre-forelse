// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest';
import type { MentorPanel } from '../src/app/MentorPanel.ts';
import { spokenSummary, TestResultsView } from '../src/app/TestResultsView.ts';

/** Le panneau des objectifs tel que le rend layout.ts, sans le reste de la page. */
function panel() {
	const root = document.createElement('div');
	root.innerHTML = `<ul class="objectives">
		<li data-test="affiche le menu"><span class="dot" aria-hidden="true"></span><span class="sr-only objective-state"></span><span>Le menu s'affiche</span></li>
		<li data-test="affiche les prix"><span class="dot" aria-hidden="true"></span><span class="sr-only objective-state"></span><span>Les prix s'affichent</span></li>
		<li data-test="absent du run"><span class="dot" aria-hidden="true"></span><span class="sr-only objective-state"></span><span>Pas lancé</span></li>
	</ul><details id="output-box" hidden><pre id="output"></pre></details>`;
	const mentor = { setError: () => {} } as unknown as MentorPanel;
	return { root, view: new TestResultsView(root, mentor) };
}

describe('TestResultsView', () => {
	it("dit l'état de chaque objectif en toutes lettres : la pastille n'est qu'une couleur", () => {
		const { root, view } = panel();
		const passed = view.show({
			exitCode: 1,
			cases: [
				{ className: 'Menu', name: 'affiche le menu', status: 'passed', timeMs: 1 },
				{ className: 'Menu', name: 'affiche les prix', status: 'failed', timeMs: 1, message: 'Pas de prix' },
			],
			output: '',
			durationMs: 10,
		});

		expect(passed).toBe(1);
		const states = [...root.querySelectorAll('.objective-state')].map((el) => el.textContent);
		expect(states).toEqual(['Atteint : ', 'Pas encore atteint : ', 'Non vérifié : ']);
	});
});

describe('spokenSummary', () => {
	it('annonce le résultat sans abréviation', () => {
		expect(spokenSummary(4, 4)).toBe('Tous les objectifs sont atteints (4 sur 4).');
		expect(spokenSummary(1, 4)).toBe('1 objectif atteint sur 4.');
		expect(spokenSummary(3, 4)).toBe('3 objectifs atteints sur 4.');
	});
});
