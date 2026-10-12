/**
 * L'index des notions (/notions). Sans JavaScript, tout s'affiche : chaque framework sous son titre, chaque thème à
 * la suite. Ce module fait des frameworks des onglets, et ajoute la recherche et le filtre par thème.
 */

/** Sans accents ni majuscules : « requetes » trouve « Requêtes N+1 ». */
export const normalize = (text: string): string =>
	text
		.normalize('NFD')
		.replace(/[̀-ͯ]/g, '')
		.toLowerCase();

export function mountConcepts(root: HTMLElement): void {
	const panels = [...root.querySelectorAll<HTMLElement>('[data-concepts-panel]')];
	const tabs = [...root.querySelectorAll<HTMLButtonElement>('[role="tab"]')];
	const search = root.querySelector<HTMLInputElement>('[data-concepts-search] input');
	if (!panels.length) return;
	let current = panels[0];
	let theme = 'all';

	const apply = () => {
		const query = search?.value.trim() ?? '';
		const wanted = normalize(query);
		let shown = 0;
		for (const group of current.querySelectorAll<HTMLElement>('[data-concepts-group]')) {
			let matches = 0;
			for (const item of group.querySelectorAll<HTMLElement>('[data-concepts-name]')) {
				item.hidden = wanted !== '' && !normalize(item.dataset.conceptsName ?? '').includes(wanted);
				if (!item.hidden) matches++;
			}
			group.hidden = matches === 0 || (theme !== 'all' && group.dataset.conceptsGroup !== theme);
			if (!group.hidden) shown++;
		}
		for (const chip of current.querySelectorAll<HTMLButtonElement>('[data-concepts-theme]')) {
			chip.setAttribute('aria-pressed', String(chip.dataset.conceptsTheme === theme));
		}
		const empty = current.querySelector<HTMLElement>('[data-concepts-empty]');
		if (empty) {
			empty.hidden = shown > 0;
			empty.querySelector('[data-concepts-query]')!.textContent = query;
		}
	};

	const select = (tab: HTMLButtonElement) => {
		for (const other of tabs) {
			other.setAttribute('aria-selected', String(other === tab));
			other.tabIndex = other === tab ? 0 : -1;
		}
		current = panels.find((panel) => panel.id === tab.getAttribute('aria-controls')) ?? panels[0];
		for (const panel of panels) panel.hidden = panel !== current;
		theme = 'all';
		apply();
	};

	// Les onglets nomment déjà chaque framework : son titre ne sert plus qu'à la lecture sans JavaScript.
	root.querySelector<HTMLElement>('[data-concepts-tabs]')?.removeAttribute('hidden');
	for (const heading of root.querySelectorAll<HTMLElement>('[data-concepts-heading]')) heading.hidden = true;
	root.querySelector<HTMLElement>('[data-concepts-search]')?.removeAttribute('hidden');
	for (const filters of root.querySelectorAll<HTMLElement>('[data-concepts-themes]')) filters.hidden = false;

	tabs.forEach((tab, index) => {
		tab.addEventListener('click', () => select(tab));
		tab.addEventListener('keydown', (event) => {
			const step = event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0;
			if (!step) return;
			event.preventDefault();
			const next = tabs[(index + step + tabs.length) % tabs.length];
			next.focus();
			select(next);
		});
	});
	for (const chip of root.querySelectorAll<HTMLButtonElement>('[data-concepts-theme]')) {
		chip.addEventListener('click', () => {
			theme = chip.dataset.conceptsTheme ?? 'all';
			apply();
		});
	}
	search?.addEventListener('input', apply);
	for (const reset of root.querySelectorAll<HTMLButtonElement>('[data-concepts-reset]')) {
		reset.addEventListener('click', () => {
			if (search) search.value = '';
			theme = 'all';
			apply();
			search?.focus();
		});
	}

	// Un lien vers /notions#concepts-laravel ouvre l'onglet Laravel.
	const linked = tabs.find((tab) => `#${tab.getAttribute('aria-controls')}` === location.hash);
	if (tabs.length) select(linked ?? tabs[0]);
	else apply();
}
