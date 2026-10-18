import type { DatabaseSnapshot, DatabaseValue, Runtime } from '@forelse/runtime-contract';
import { m } from '../i18n';
import { escapeHtml } from './html';

/** Une valeur de la base en HTML : NULL et les octets illisibles (colonne chiffrée, BLOB) se distinguent du texte. */
export function databaseValueHtml(value: DatabaseValue): string {
	if (value === null) return '<span class="db-null">NULL</span>';
	if (typeof value === 'object') {
		const more = value.hex.length / 2 < value.bytes ? '…' : '';
		return `<span class="db-bytes" title="${escapeHtml(m.database.bytesTitle(value.bytes))}">${escapeHtml(value.hex)}${more}</span> <small class="db-size">${escapeHtml(m.database.bytes(value.bytes))}</small>`;
	}
	return escapeHtml(String(value));
}

/** Les bases du projet en HTML : une section par fichier, un tableau par table. */
export function databaseHtml(snapshots: DatabaseSnapshot[]): string {
	if (!snapshots.length) return `<p class="db-empty">${escapeHtml(m.database.none)}</p>`;
	return snapshots
		.map((snapshot) => {
			const tables = snapshot.error
				? `<p class="db-error">${escapeHtml(m.database.unreadable(snapshot.error))}</p>`
				: snapshot.tables.length
					? snapshot.tables.map((table) => {
						const head = table.columns.map((column) => `<th>${escapeHtml(column)}</th>`).join('');
						const rows = table.rows.map((row) => `<tr>${row.map((value) => `<td>${databaseValueHtml(value)}</td>`).join('')}</tr>`).join('');
						const shown = table.rows.length < table.count ? m.database.firstRows(table.rows.length, table.count) : m.database.rows(table.count);
						return `<h4>${escapeHtml(table.name)} <small>${escapeHtml(shown)}</small></h4><div class="db-scroll"><table><thead><tr>${head}</tr></thead><tbody>${rows}</tbody></table></div>`;
					}).join('')
					: `<p class="db-empty">${escapeHtml(m.database.noTables)}</p>`;
			return `<section><h3>${escapeHtml(snapshot.file)}</h3>${tables}</section>`;
		})
		.join('');
}

/**
 * Onglet « Base » : les bases SQLite de l'aperçu, telles que l'application les a laissées.
 *
 * Il n'apparaît qu'une fois une base créée (au premier chargement de l'aperçu, le plus souvent) : un exercice sans
 * base de données n'en a pas. Il se relit quand on l'ouvre, avec ⟳, et après chaque requête ou commande tant qu'il
 * est affiché.
 */
export class DatabasePanel {
	private readonly content: HTMLElement;
	private found = false;
	private timer?: ReturnType<typeof setTimeout>;

	constructor(
		private readonly pane: HTMLElement,
		private readonly tab: HTMLElement,
		private readonly inspect: NonNullable<Runtime['inspectDatabase']>,
	) {
		this.content = pane.querySelector<HTMLElement>('.db-content')!;
		pane.querySelector('.db-refresh')!.addEventListener('click', () => void this.refresh());
		tab.addEventListener('click', () => void this.refresh());
	}

	/** La base a pu changer (une requête, une commande) : relue un peu plus tard, une fois pour une rafale. */
	changed(): void {
		if (this.found && this.pane.hidden) return;
		clearTimeout(this.timer);
		this.timer = setTimeout(() => void this.refresh(), 300);
	}

	async refresh(): Promise<void> {
		let snapshots: DatabaseSnapshot[];
		try {
			snapshots = await this.inspect();
		} catch (error) {
			if (!this.pane.hidden) this.content.innerHTML = `<p class="db-error">${escapeHtml(m.database.unreadable(error instanceof Error ? error.message : String(error)))}</p>`;
			return;
		}
		if (snapshots.length) {
			this.found = true;
			this.tab.hidden = false;
		}
		if (!this.pane.hidden) this.content.innerHTML = databaseHtml(snapshots);
	}
}
