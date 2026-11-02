/**
 * Les paquets Composer des bacs à sable PHP et Symfony (bouton « Paquets ») : un catalogue fermé, empaqueté sur le serveur
 * (environments/bin/build-paquets.php). Chaque paquet est une archive de vendor/, ses dépendances comprises, servie
 * depuis /envs comme l'environnement : PHP n'a jamais besoin du réseau, et le code d'un lien partagé non plus.
 *
 * Ajouter un paquet écrit ses fichiers dans le projet, et sa table d'autoload dans vendor/forelse-paquets/, que lit
 * le chargeur du projet (.forelse/paquets.php) : `require 'vendor/autoload.php'` suffit. Le retirer efface sa table :
 * ses classes ne se chargent plus (les fichiers restent, invisibles, jusqu'au prochain démarrage).
 *
 * Dans un projet Symfony, chaque paquet apporte aussi ses recettes Flex (recipes.ts), appliquées après l'archive et
 * défaites au retrait.
 */
import { strFromU8, unzipSync } from 'fflate';
import { m } from '../i18n';
import { escapeHtml } from './html';
import { applyRecipe, parseRecipe, type Recipe, type RecipeChanges, recipesReport, unapplyRecipe } from './recipes';

export interface CatalogEntry {
	nom: string;
	slug: string;
	version: string;
	description: Record<string, string>;
	dependances: string[];
	archive: string;
	poids: number;
	/** Les recettes Flex du paquet et de ses dépendances (bac à sable Symfony), dans l'ordre d'installation. */
	recettes?: Recipe[];
}

/** Ce qu'un ajout ou un retrait a changé dans le projet (recettes Flex), et ce que Flex en aurait écrit. */
export interface PackageChanges extends RecipeChanges {
	rapport: string;
}

type Store = Pick<Storage, 'getItem' | 'setItem'>;
interface Writer {
	writeFiles(files: Record<string, string>): Promise<void>;
	writeFile(path: string, content: string): Promise<void>;
	readFile(path: string): Promise<string | null>;
	deleteFile(path: string): Promise<void>;
}

/** Un nom de paquet Composer (vendeur/paquet), le seul format qu'accepte un lien. */
const NAME = /^[a-z0-9][a-z0-9_.-]*\/[a-z0-9][a-z0-9_.-]*$/;

export const packagesKey = (exerciseId: string) => `forelse:bac-a-sable:paquets:${exerciseId}`;

/** Le chemin de la table d'autoload d'un paquet : la présence de ce fichier, c'est le paquet ajouté. */
export const tablePath = (entry: Pick<CatalogEntry, 'slug'>) => `vendor/forelse-paquets/${entry.slug}.php`;

/** Le catalogue, lu tel que le serveur l'a écrit ; une entrée incomplète est écartée. */
export function parseCatalog(data: unknown): CatalogEntry[] {
	const paquets = (data as { paquets?: unknown } | null)?.paquets;
	if (!Array.isArray(paquets)) return [];
	return paquets
		.filter(
			(p): p is CatalogEntry =>
				typeof p === 'object' && p !== null && typeof p.nom === 'string' && NAME.test(p.nom) && typeof p.slug === 'string' && /^[a-z0-9_.-]+$/.test(p.slug) && typeof p.archive === 'string' && /^[\w.-]+\.zip$/.test(p.archive),
		)
		.map((p) => ({ ...p, recettes: Array.isArray(p.recettes) ? p.recettes.map(parseRecipe).filter((r): r is Recipe => r !== null) : [] }));
}

/**
 * Les fichiers à effacer après un ajout ou un retrait : le cache du conteneur de Symfony, qui ne remarquerait pas une
 * classe apparue dans vendor/. Seulement sous var/.
 */
export function parseInvalidation(data: unknown): string[] {
	const invalider = (data as { invalider?: unknown } | null)?.invalider;
	return Array.isArray(invalider) ? invalider.filter((p): p is string => typeof p === 'string' && p.startsWith('var/') && !p.split('/').includes('..')) : [];
}

/** Les noms reçus (lien, brouillon) qui sont au catalogue, dans son ordre, sans doublon. */
export function knownPackages(names: readonly unknown[], catalog: CatalogEntry[]): string[] {
	const wanted = new Set(names.filter((n): n is string => typeof n === 'string'));
	return catalog.filter((entry) => wanted.has(entry.nom)).map((entry) => entry.nom);
}

export function readSelection(store: Store | null, exerciseId: string): string[] {
	try {
		const saved = JSON.parse(store?.getItem(packagesKey(exerciseId)) ?? '[]') as unknown;
		return Array.isArray(saved) ? saved.filter((n): n is string => typeof n === 'string') : [];
	} catch {
		return [];
	}
}

export function writeSelection(store: Store | null, exerciseId: string, names: string[]): void {
	try {
		store?.setItem(packagesKey(exerciseId), JSON.stringify(names));
	} catch {
		// stockage indisponible : les paquets ne seront simplement pas repris au prochain passage
	}
}

/** Les fichiers d'une archive de paquet : seulement sous vendor/, en texte (l'empaqueteur a écarté les binaires). */
export function archiveFiles(zip: Uint8Array): Record<string, string> {
	const files: Record<string, string> = {};
	for (const [path, bytes] of Object.entries(unzipSync(zip))) {
		if (path.endsWith('/') || !path.startsWith('vendor/') || path.split('/').some((part) => part === '..' || part === '')) continue;
		files[path] = strFromU8(bytes);
	}
	return files;
}

/**
 * Ce qu'une commande de la console demande au catalogue : `composer require` pose des paquets avant que PHP ne mette
 * composer.json à jour, `composer remove` les oublie après. Les noms seuls, sans contrainte de version (« :^1.3 »).
 */
export function composerRequest(args: readonly string[]): { action: 'require' | 'remove'; names: string[] } | null {
	if (args[0] !== 'composer') return null;
	const action = ({ require: 'require', req: 'require', r: 'require', remove: 'remove', rm: 'remove', uninstall: 'remove' } as const)[args[1] ?? ''];
	if (!action) return null;
	const names = args
		.slice(2)
		.filter((arg) => !arg.startsWith('-'))
		.map((arg) => arg.split(/[:=]/)[0]!.toLowerCase())
		.filter((name) => NAME.test(name));
	return { action, names };
}

/** Les paquets que demande un composer.json (require et require-dev), tel que l'apprenant l'a laissé. */
export function requiredPackages(composerJson: string | null): string[] {
	try {
		const data = JSON.parse(composerJson ?? '') as { require?: unknown; 'require-dev'?: unknown };
		return [data.require, data['require-dev']].flatMap((section) => (section && typeof section === 'object' ? Object.keys(section) : [])).map((name) => name.toLowerCase()).filter((name) => NAME.test(name));
	} catch {
		return [];
	}
}

/** Le poids d'une archive, tel qu'on l'affiche (« 412 Ko », « 1,8 Mo »). */
export function formatSize(bytes: number, locale: string): string {
	const ko = bytes / 1024;
	if (ko < 1024) return m.packages.kilobytes(Math.max(1, Math.round(ko)).toLocaleString(locale));
	return m.packages.megabytes((ko / 1024).toLocaleString(locale, { maximumFractionDigits: 1 }));
}

/**
 * Les paquets d'un projet : ajouter, retirer, et savoir ce qui est là. Les archives se téléchargent depuis le
 * dossier du catalogue (`baseUrl`), une fois par page.
 */
export class Packages {
	private readonly installed = new Set<string>();
	private readonly archives = new Map<string, Promise<Record<string, string>>>();

	constructor(
		readonly catalog: CatalogEntry[],
		private readonly baseUrl: URL,
		private readonly runtime: Writer,
		private readonly fetcher: typeof fetch = fetch.bind(globalThis),
		private readonly invalidate: string[] = [],
	) {}

	get names(): string[] {
		return this.catalog.filter((entry) => this.installed.has(entry.nom)).map((entry) => entry.nom);
	}

	has(name: string): boolean {
		return this.installed.has(name);
	}

	/** Ajoute le paquet, puis applique ses recettes Flex (celles qu'un autre paquet ajouté n'a pas déjà appliquées). */
	async add(name: string): Promise<PackageChanges> {
		const changes: PackageChanges = { fichiers: {}, supprimes: [], gardes: [], rapport: '' };
		const entry = this.entry(name);
		if (!entry || this.installed.has(name)) return changes;
		await this.runtime.writeFiles(await this.download(entry));
		const dejaAppliquees = this.appliedRecipes();
		this.installed.add(name);
		const nouvelles = (entry.recettes ?? []).filter((recipe) => !dejaAppliquees.has(recipe.paquet));
		for (const recipe of nouvelles) await applyRecipe(this.runtime, recipe, changes);
		changes.rapport = recipesReport(nouvelles);
		await this.invalidateCache();
		return changes;
	}

	/** Un paquet que `composer remove` a déjà retiré du projet (sa table effacée par PHP) : il pourra revenir. */
	forget(name: string): void {
		this.installed.delete(name);
	}

	/** Retire le paquet et défait ses recettes, sauf celles dont un autre paquet ajouté a encore besoin. */
	async remove(name: string): Promise<PackageChanges> {
		const changes: PackageChanges = { fichiers: {}, supprimes: [], gardes: [], rapport: '' };
		const entry = this.entry(name);
		if (!entry || !this.installed.has(name)) return changes;
		await this.runtime.deleteFile(tablePath(entry));
		this.installed.delete(name);
		const encoreUtiles = this.appliedRecipes();
		const aDefaire = (entry.recettes ?? []).filter((recipe) => !encoreUtiles.has(recipe.paquet)).reverse();
		for (const recipe of aDefaire) await unapplyRecipe(this.runtime, recipe, changes);
		changes.rapport = recipesReport(aDefaire, true);
		await this.invalidateCache();
		return changes;
	}

	/** Les paquets dont la recette est appliquée : ceux des paquets ajoutés et de leurs dépendances. */
	private appliedRecipes(): Set<string> {
		return new Set(this.catalog.filter((entry) => this.installed.has(entry.nom)).flatMap((entry) => (entry.recettes ?? []).map((recipe) => recipe.paquet)));
	}

	private async invalidateCache(): Promise<void> {
		for (const path of this.invalidate) await this.runtime.deleteFile(path);
	}

	private entry(name: string): CatalogEntry | undefined {
		return this.catalog.find((entry) => entry.nom === name);
	}

	private download(entry: CatalogEntry): Promise<Record<string, string>> {
		let archive = this.archives.get(entry.nom);
		if (!archive) {
			archive = (async () => {
				const response = await this.fetcher(new URL(entry.archive, this.baseUrl));
				if (!response.ok) throw new Error(m.packages.downloadFailed(entry.nom, response.status));
				return archiveFiles(new Uint8Array(await response.arrayBuffer()));
			})();
			// Un échec (réseau coupé) se retente au prochain clic.
			archive.catch(() => this.archives.delete(entry.nom));
			this.archives.set(entry.nom, archive);
		}
		return archive;
	}
}

/** La liste du catalogue, une case par paquet ; `checked` dit ceux du projet. */
export function packagesListHtml(catalog: CatalogEntry[], checked: (name: string) => boolean, lang: string): string {
	return `<ul class="packages-list">${catalog
		.map(
			(entry) => `<li><label><input type="checkbox" value="${escapeHtml(entry.nom)}"${checked(entry.nom) ? ' checked' : ''}> <span class="package-name">${escapeHtml(entry.nom)}</span> <span class="package-meta">${escapeHtml(entry.version)} · ${escapeHtml(formatSize(entry.poids, lang))}</span></label><span class="package-description">${escapeHtml(entry.description[lang] ?? entry.description.fr ?? '')}</span></li>`,
		)
		.join('')}</ul>`;
}

/**
 * La boîte « Paquets » : cocher ajoute le paquet au projet, décocher le retire. `onChange` suit chaque changement
 * réussi (enregistrer le choix, recharger l'aperçu) ; une case dont l'ajout échoue se décoche et le dit.
 */
export function packagesDialog(
	host: HTMLElement,
	packages: Packages,
	options: { lang: string; requestUrl: string | null; intro?: string; onChange: (name: string, added: boolean, changes: PackageChanges) => void },
): void {
	const dialog = document.createElement('dialog');
	dialog.className = 'feedback packages-dialog';
	dialog.setAttribute('aria-labelledby', 'packages-dialog-title');
	dialog.innerHTML = `<h2 id="packages-dialog-title">${m.packages.title}</h2>
		<p>${options.intro ?? m.packages.intro}</p>
		${packagesListHtml(packages.catalog, (name) => packages.has(name), options.lang)}
		<p class="packages-status" role="status"></p>
		${options.requestUrl ? `<p class="packages-request"><a href="${escapeHtml(options.requestUrl)}" target="_blank" rel="noopener">${m.packages.request}</a></p>` : ''}
		<p class="confirm-actions"><button type="button" class="ghost" data-close>${m.common.close}</button></p>`;
	host.append(dialog);
	const status = dialog.querySelector<HTMLElement>('.packages-status')!;
	for (const box of dialog.querySelectorAll<HTMLInputElement>('input[type="checkbox"]')) {
		box.addEventListener('change', async () => {
			const name = box.value;
			const adding = box.checked;
			box.disabled = true;
			status.textContent = adding ? m.packages.adding(name) : m.packages.removing(name);
			try {
				const changes = await (adding ? packages.add(name) : packages.remove(name));
				status.textContent = adding ? m.packages.added(name) : m.packages.removed(name);
				if (changes.gardes.length) status.textContent += ` ${m.packages.kept(changes.gardes.join(', '))}`;
				options.onChange(name, adding, changes);
			} catch (error) {
				box.checked = !adding;
				status.textContent = m.packages.failed(name, error instanceof Error ? error.message : String(error));
			} finally {
				box.disabled = false;
			}
		});
	}
	dialog.querySelector('[data-close]')!.addEventListener('click', () => dialog.close());
	dialog.addEventListener('close', () => dialog.remove());
	dialog.showModal();
}
