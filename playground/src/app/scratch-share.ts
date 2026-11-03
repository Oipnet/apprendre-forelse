/**
 * La boîte de partage du bac à sable. Case « Lien court » cochée par défaut : le code part sur le serveur, qui le garde
 * sous un identifiant de 8 caractères (/bac-a-sable/s/<id>, contrat : App\Controller\ScratchShareController), jusqu'à
 * la date choisie ou sans limite. Décochée : le lien long d'avant, qui porte tout le code après le # (scratch.ts), et
 * rien n'est stocké.
 *
 * Sans compte, c'est le navigateur qui a créé un lien court qui peut le supprimer : il en garde la clé (localStorage),
 * et la boîte liste ces liens avec un bouton « Supprimer ».
 */
import { m } from '../i18n';
import { escapeHtml } from './html';
import { mountShare, shareHtml, type ShareLinks } from './share';

export type ShortLinkDuration = 'jamais' | '1a' | '6m' | '1m';
export const DURATIONS: readonly ShortLinkDuration[] = ['jamais', '1a', '6m', '1m'];

/** Un lien court créé par ce navigateur, tel que l'API le renvoie (plus de quoi le reconnaître). */
export interface ShortLink {
	id: string;
	url: string;
	/** null : n'expire jamais. */
	expiresAt: string | null;
	deleteKey: string;
	deleteUrl: string;
	share: ShareLinks;
	/** L'empreinte du code et de la durée : un deuxième clic sur Partager, sans modification, reprend le même lien. */
	empreinte?: string;
	createdAt?: string;
}

const STORE_KEY = 'forelse:bac-a-sable:liens-courts';
const PREFS_KEY = 'forelse:bac-a-sable:partage';
/** Assez pour retrouver ses liens récents, pas de quoi faire grossir le stockage du navigateur. */
const MAX_STORED = 50;

/** Une empreinte courte (FNV-1a 32 bits) : pas un secret, juste de quoi reconnaître le même code. */
export function fingerprint(text: string): string {
	let hash = 0x811c9dc5;
	for (let i = 0; i < text.length; i++) {
		hash ^= text.charCodeAt(i);
		hash = Math.imul(hash, 0x01000193);
	}
	return (hash >>> 0).toString(36) + ':' + text.length.toString(36);
}

/** Le stockage du navigateur, ou null s'il est bloqué (navigation privée stricte, réglages). */
function storage(): Storage | null {
	try {
		return localStorage;
	} catch {
		return null;
	}
}

/** Les liens courts de ce navigateur, les plus récents d'abord, sans ceux qui ont expiré. */
export class ShortLinkStore {
	constructor(private readonly store: Storage | null = storage(), private readonly now: () => number = Date.now) {}

	list(): ShortLink[] {
		try {
			const raw = JSON.parse(this.store?.getItem(STORE_KEY) ?? '[]') as unknown;
			if (!Array.isArray(raw)) return [];
			return raw.filter((link): link is ShortLink => typeof link?.id === 'string' && typeof link?.url === 'string' && typeof link?.deleteKey === 'string' && typeof link?.deleteUrl === 'string' && (link.expiresAt === null || Date.parse(link.expiresAt) > this.now()));
		} catch {
			return [];
		}
	}

	add(link: ShortLink): void {
		this.save([link, ...this.list().filter((other) => other.id !== link.id)].slice(0, MAX_STORED));
	}

	remove(id: string): void {
		this.save(this.list().filter((link) => link.id !== id));
	}

	/** Le lien déjà créé pour ce code et cette durée, s'il existe encore. */
	find(empreinte: string): ShortLink | null {
		return this.list().find((link) => link.empreinte === empreinte) ?? null;
	}

	private save(links: ShortLink[]): void {
		try {
			this.store?.setItem(STORE_KEY, JSON.stringify(links));
		} catch {
			// Stockage plein ou bloqué : le lien marche, il ne sera simplement pas supprimable d'ici.
		}
	}
}

/** Les choix de la boîte, retenus d'un partage à l'autre : lien court ou non, et sa durée. */
export interface SharePrefs {
	short: boolean;
	duree: ShortLinkDuration;
}

export function readPrefs(store: Storage | null = storage()): SharePrefs {
	try {
		const raw = JSON.parse(store?.getItem(PREFS_KEY) ?? '{}') as Partial<SharePrefs>;
		return { short: raw.short !== false, duree: DURATIONS.includes(raw.duree as ShortLinkDuration) ? (raw.duree as ShortLinkDuration) : 'jamais' };
	} catch {
		return { short: true, duree: 'jamais' };
	}
}

export function writePrefs(prefs: SharePrefs, store: Storage | null = storage()): void {
	try {
		store?.setItem(PREFS_KEY, JSON.stringify(prefs));
	} catch {
		// Bloqué : les choix par défaut reviendront au prochain partage.
	}
}

/** Crée le lien court de ce code ; null si le serveur refuse ou ne répond pas (le lien long prend alors le relais). */
export async function createShortLink(url: string, fragment: string, duree: ShortLinkDuration): Promise<ShortLink | null> {
	try {
		const response = await fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { accept: 'application/json', 'content-type': 'application/json' },
			body: JSON.stringify({ code: fragment, duree }),
		});
		if (!response.ok) return null;
		const link = (await response.json()) as ShortLink;
		return typeof link.url === 'string' && typeof link.deleteKey === 'string' ? link : null;
	} catch {
		return null;
	}
}

/** Supprime un lien court ; vrai s'il n'existe plus (déjà supprimé ou expiré compris). */
export async function deleteShortLink(link: Pick<ShortLink, 'deleteUrl' | 'deleteKey'>): Promise<boolean> {
	try {
		const response = await fetch(link.deleteUrl, {
			method: 'DELETE',
			credentials: 'same-origin',
			headers: { accept: 'application/json', 'content-type': 'application/json' },
			body: JSON.stringify({ cle: link.deleteKey }),
		});
		return response.ok || response.status === 404;
	} catch {
		return false;
	}
}

export interface ScratchShareOptions {
	title: string;
	/** Le code du projet, au format du lien long (« code=… »). */
	fragment: string;
	/** Les liens longs, le code après le #. */
	longLinks: ShareLinks;
	/** L'API des liens courts ; null : pas de case, le lien long seulement. */
	shortLinksUrl: string | null;
	mesure: Record<string, string>;
	store?: ShortLinkStore;
	/** La langue des dates (« 3 nov. 2027 »). */
	lang?: string;
}

/** Ouvre la boîte de partage du bac à sable. */
export function scratchShareDialog(host: HTMLElement, options: ScratchShareOptions): void {
	const store = options.store ?? new ShortLinkStore();
	const prefs = readPrefs();
	const t = m.scratch.shortLink;
	const dialog = document.createElement('dialog');
	dialog.className = 'feedback share-dialog';
	dialog.setAttribute('aria-labelledby', 'share-dialog-title');
	const shortControls = options.shortLinksUrl
		? `<div class="share-short">
			<label><input type="checkbox" data-short${prefs.short ? ' checked' : ''} aria-describedby="share-short-hint"> ${t.label}</label>
			<button type="button" class="share-short-info" data-short-info aria-expanded="false" aria-controls="share-short-hint" title="${escapeHtml(t.hint)}" aria-label="${escapeHtml(t.infoLabel)}">?</button>
			<label class="share-short-duree">${t.duration}
				<select data-duree>${DURATIONS.map((d) => `<option value="${d}"${d === prefs.duree ? ' selected' : ''}>${t.durations[d]}</option>`).join('')}</select>
			</label>
			<p class="share-short-hint" id="share-short-hint" hidden>${t.hint}</p>
		</div>`
		: '';
	dialog.innerHTML = `<h2 id="share-dialog-title">${escapeHtml(options.title)}</h2>
		${shortControls}
		<p class="share-url"><input type="text" readonly data-share-url aria-label="${escapeHtml(t.urlLabel)}"></p>
		<div data-share-slot></div>
		<p class="share-status" role="status" data-short-status></p>
		<div class="share-short-list" data-short-list hidden></div>
		<p class="confirm-actions"><button type="button" class="ghost" data-close>${m.common.close}</button></p>`;
	host.append(dialog);

	const checkbox = dialog.querySelector<HTMLInputElement>('[data-short]');
	const select = dialog.querySelector<HTMLSelectElement>('[data-duree]');
	const durationLabel = dialog.querySelector<HTMLElement>('.share-short-duree');
	const info = dialog.querySelector<HTMLButtonElement>('[data-short-info]');
	const hint = dialog.querySelector<HTMLElement>('#share-short-hint');
	const urlField = dialog.querySelector<HTMLInputElement>('[data-share-url]')!;
	const slot = dialog.querySelector<HTMLElement>('[data-share-slot]')!;
	const status = dialog.querySelector<HTMLElement>('[data-short-status]')!;
	const list = dialog.querySelector<HTMLElement>('[data-short-list]')!;
	const dates = new Intl.DateTimeFormat(options.lang ?? (document.documentElement.lang || 'fr'), { dateStyle: 'medium' });
	urlField.addEventListener('focus', () => urlField.select());

	info?.addEventListener('click', () => {
		const open = hint!.hidden;
		hint!.hidden = !open;
		info.setAttribute('aria-expanded', String(open));
	});

	const show = (links: ShareLinks) => {
		urlField.value = links.lien;
		slot.innerHTML = shareHtml(links, m.share.on);
		mountShare(slot.querySelector<HTMLElement>('[data-share]')!, links, options.mesure);
	};

	/** Chaque rendu en remplace un autre plus ancien : seul le dernier affiche son résultat. */
	let turn = 0;
	const render = async () => {
		const mine = ++turn;
		const short = !!checkbox?.checked && options.shortLinksUrl !== null;
		if (durationLabel) durationLabel.hidden = !short;
		status.textContent = '';
		if (!short) {
			show(options.longLinks);
			return;
		}
		const duree = (select?.value ?? 'jamais') as ShortLinkDuration;
		const empreinte = fingerprint(`${duree}\n${options.fragment}`);
		const known = store.find(empreinte);
		if (known) {
			show(known.share);
			return;
		}
		urlField.value = '';
		slot.innerHTML = '';
		status.textContent = t.creating;
		const created = await createShortLink(options.shortLinksUrl!, options.fragment, duree);
		if (mine !== turn) return;
		if (!created) {
			status.textContent = t.failed;
			show(options.longLinks);
			return;
		}
		store.add({ ...created, empreinte, createdAt: new Date().toISOString() });
		status.textContent = '';
		show(created.share);
		renderList();
	};

	const renderList = () => {
		const links = store.list();
		list.hidden = links.length === 0 || !options.shortLinksUrl;
		list.innerHTML = `<h3>${t.mine}</h3>
			<ul>${links
				.map((link) => `<li>
					<a href="${escapeHtml(link.url)}" target="_blank" rel="noopener">${escapeHtml(link.url.replace(/^https?:\/\//, ''))}</a>
					<span class="share-short-expiry">${link.expiresAt ? t.expires(dates.format(new Date(link.expiresAt))) : t.never}</span>
					<button type="button" class="ghost small" data-delete="${escapeHtml(link.id)}">${t.delete}</button>
				</li>`)
				.join('')}</ul>`;
	};

	list.addEventListener('click', async (event) => {
		const button = (event.target as HTMLElement).closest<HTMLButtonElement>('[data-delete]');
		const link = button ? store.list().find((other) => other.id === button.dataset.delete) : null;
		if (!button || !link) return;
		button.disabled = true;
		if (!(await deleteShortLink(link))) {
			button.disabled = false;
			status.textContent = t.deleteFailed;
			return;
		}
		store.remove(link.id);
		status.textContent = t.deleted;
		renderList();
		// Le lien affiché vient d'être supprimé : on ne le recrée pas d'office, le lien long le remplace.
		if (urlField.value === link.share.lien && checkbox) {
			checkbox.checked = false;
			writePrefs({ short: false, duree: (select?.value ?? 'jamais') as ShortLinkDuration });
			void render().then(() => (status.textContent = t.deleted));
		}
	});

	const changed = () => {
		writePrefs({ short: !!checkbox?.checked, duree: (select?.value ?? 'jamais') as ShortLinkDuration });
		void render();
	};
	checkbox?.addEventListener('change', changed);
	select?.addEventListener('change', changed);
	dialog.querySelector('[data-close]')!.addEventListener('click', () => dialog.close());
	dialog.addEventListener('close', () => dialog.remove());
	renderList();
	void render();
	dialog.showModal();
}
