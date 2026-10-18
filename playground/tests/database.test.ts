// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest';
import type { DatabaseSnapshot } from '@forelse/runtime-contract';
import { DatabasePanel, databaseHtml, databaseValueHtml } from '../src/app/database.ts';

const patient: DatabaseSnapshot = {
	file: 'var/data_dev.db',
	tables: [
		{
			name: 'patient',
			columns: ['id', 'nom', 'telephone'],
			rows: [
				[1, 'Ada <Lovelace>', { bytes: 61, hex: '02001001a14f' }],
				[2, 'Alan Turing', null],
			],
			count: 2,
		},
	],
};

/** L'onglet et son volet tels que les rend layout.ts. */
function panel(inspect: () => Promise<DatabaseSnapshot[]>) {
	const root = document.createElement('div');
	root.innerHTML = `<button data-pane="database" hidden>Base</button>
		<div class="pane-database" hidden><div class="db-bar"><button class="db-refresh">⟳</button></div><div class="db-content"></div></div>`;
	const tab = root.querySelector<HTMLElement>('button[data-pane]')!;
	const pane = root.querySelector<HTMLElement>('.pane-database')!;
	return { tab, pane, content: pane.querySelector<HTMLElement>('.db-content')!, panel: new DatabasePanel(pane, tab, inspect) };
}

describe('databaseHtml', () => {
	it('montre le texte échappé, NULL à part, et les octets illisibles par leur taille et leur début', () => {
		const html = databaseHtml([patient]);
		expect(html).toContain('<h3>var/data_dev.db</h3>');
		expect(html).toContain('<th>telephone</th>');
		expect(html).toContain('Ada &lt;Lovelace&gt;');
		expect(html).toContain('<span class="db-null">NULL</span>');
		expect(html).toContain('02001001a14f…');
		expect(html).toContain('61 octets');
		expect(html).toContain('2 lignes');
	});

	it('dit quand la table a plus de lignes que celles montrées', () => {
		const html = databaseHtml([{ ...patient, tables: [{ ...patient.tables[0], count: 250 }] }]);
		expect(html).toContain('2 premières lignes sur 250');
	});

	it("n'ajoute pas de points de suspension quand toute la valeur est montrée", () => {
		expect(databaseValueHtml({ bytes: 2, hex: 'cafe' })).toContain('cafe</span>');
	});

	it('dit pourquoi une base est illisible, et quand il n’y en a aucune', () => {
		expect(databaseHtml([{ file: 'var/app.db', tables: [], error: 'file is not a database' }])).toContain('Base illisible : file is not a database');
		expect(databaseHtml([])).toContain('Aucune base SQLite');
	});
});

describe('DatabasePanel', () => {
	it("garde l'onglet caché tant que le projet n'a pas de base, puis le montre", async () => {
		let snapshots: DatabaseSnapshot[] = [];
		const { tab, panel: base } = panel(async () => snapshots);
		await base.refresh();
		expect(tab.hidden).toBe(true);
		snapshots = [patient];
		await base.refresh();
		expect(tab.hidden).toBe(false);
	});

	it('remplit le volet affiché, et le laisse tel quel quand il est caché', async () => {
		const { pane, content, panel: base } = panel(async () => [patient]);
		await base.refresh();
		expect(content.innerHTML).toBe('');
		pane.hidden = false;
		await base.refresh();
		expect(content.innerHTML).toContain('Ada &lt;Lovelace&gt;');
	});

	it("affiche l'erreur du runtime plutôt que de rester vide", async () => {
		const { pane, content, panel: base } = panel(async () => {
			throw new Error('PHP ne répond plus');
		});
		pane.hidden = false;
		await base.refresh();
		expect(content.textContent).toContain('PHP ne répond plus');
	});
});
