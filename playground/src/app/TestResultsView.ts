import type { TestRunResult } from '@forelse/runtime-contract';
import type { MentorPanel } from './MentorPanel';
import { summaryOf } from './results';

/** Ce qu'un run de tests affiche : l'état de chaque objectif, la sortie du lanceur, l'erreur à expliquer. */
export class TestResultsView {
	private readonly $ = <T extends HTMLElement>(selector: string) => this.root.querySelector<T>(selector)!;

	constructor(
		private readonly root: HTMLElement,
		private readonly mentor: MentorPanel,
	) {}

	/** Coche les objectifs atteints ; rend leur nombre. */
	show(result: TestRunResult): number {
		const byName = new Map(result.cases.map((c) => [c.name, c]));
		let passed = 0;
		for (const li of this.root.querySelectorAll<HTMLLIElement>('.objectives li')) {
			const test = byName.get(li.dataset.test!);
			const ok = test?.status === 'passed';
			passed += ok ? 1 : 0;
			li.dataset.state = !test ? 'unknown' : ok ? 'passed' : 'failed';
			li.querySelector('.why')?.remove();
			if (test && !ok && test.message) {
				const why = document.createElement('div');
				why.className = 'why';
				why.textContent = summaryOf(test);
				li.append(why);
			}
		}
		this.$('#output').textContent = result.output;
		this.$('#output-box').hidden = false;
		if (!result.cases.length) this.$<HTMLDetailsElement>('#output-box').open = true;
		// Une erreur (exception, fatal) se distingue d'un échec d'assertion, que les indices couvrent.
		const errors = result.cases.filter((c) => c.status === 'error');
		this.mentor.setError('tests', !result.cases.length ? result.output.slice(-6000) : errors.length ? errors.map((c) => `${c.name}\n${c.message ?? ''}`).join('\n\n') : null);
		return passed;
	}

	/** Le run n'a pas abouti (runtime arrêté…) : l'erreur, à la place de la sortie. */
	showError(error: unknown): void {
		this.$('#output').textContent = String(error);
		this.$('#output-box').hidden = false;
		this.mentor.setError('tests', String(error));
	}
}
