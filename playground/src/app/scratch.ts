/**
 * Le bac à sable : son code voyage dans l'adresse partagée, après « #code= ». Le fragment n'est jamais envoyé au
 * serveur (ni à la mesure d'audience, voir data-exclude-hash dans _head.html.twig) : rien n'est stocké côté plateforme,
 * et le lien suffit à retrouver le projet.
 *
 * Seuls les fichiers modifiés depuis le départ partent, compressés (deflate), puis encodés en base64url.
 */
import { deflateSync, Inflate, strFromU8, strToU8 } from 'fflate';
import type { ShareLinks } from './share';

const PREFIX = 'code=';
/** Le fragment qui demande un projet vierge (liens de la page /bac-a-sable). */
export const NEW_PROJECT = 'nouveau';
/** Un fragment plus long n'est pas lu : rien de ce qu'on tape à la main n'en approche. */
const MAX_FRAGMENT = 200_000;
/** Le code décompressé, au plus : une adresse piégée ne fait pas gonfler la mémoire de la page. */
const MAX_BYTES = 1_000_000;
const MAX_FILES = 100;

/** Les fichiers qui diffèrent du projet de départ (un fichier créé en fait partie). */
export function changedFiles(current: Record<string, string>, starting: Record<string, string>): Record<string, string> {
	return Object.fromEntries(Object.entries(current).filter(([path, content]) => starting[path] !== content));
}

/** Le fragment d'adresse (sans « # ») qui porte ces fichiers. */
export function encodeFragment(files: Record<string, string>): string {
	const bytes = deflateSync(strToU8(JSON.stringify({ v: 1, files })), { level: 9 });
	let binary = '';
	for (let i = 0; i < bytes.length; i += 0x8000) binary += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
	return PREFIX + btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

/**
 * Les fichiers portés par un fragment (`location.hash`, avec ou sans « # ») ; null s'il n'en porte pas, ou s'il est
 * illisible. Un chemin qui remonte (`..`) ou part de la racine est écarté : le projet ne s'écrit que sous lui-même.
 */
export function decodeFragment(hash: string): Record<string, string> | null {
	const fragment = hash.replace(/^#/, '');
	if (!fragment.startsWith(PREFIX) || fragment.length > MAX_FRAGMENT) return null;
	try {
		const binary = atob(fragment.slice(PREFIX.length).replace(/-/g, '+').replace(/_/g, '/'));
		const bytes = Uint8Array.from(binary, (c) => c.charCodeAt(0));
		const chunks: Uint8Array[] = [];
		let size = 0;
		const inflate = new Inflate((chunk) => {
			size += chunk.length;
			if (size > MAX_BYTES) throw new RangeError('too-large');
			chunks.push(chunk);
		});
		inflate.push(bytes, true);
		const all = new Uint8Array(size);
		let offset = 0;
		for (const chunk of chunks) {
			all.set(chunk, offset);
			offset += chunk.length;
		}
		const data = JSON.parse(strFromU8(all)) as { v?: unknown; files?: unknown };
		if (data.v !== 1 || typeof data.files !== 'object' || data.files === null) return null;
		const files: Record<string, string> = {};
		for (const [path, content] of Object.entries(data.files).slice(0, MAX_FILES)) {
			if (typeof content !== 'string' || path.startsWith('/') || path.split('/').some((part) => part === '..' || part === '')) continue;
			files[path] = content;
		}
		return files;
	} catch {
		return null;
	}
}

/**
 * Les liens de partage de la page, avec le code : le fragment suit chaque adresse. Bluesky compte toute l'adresse
 * dans ses 300 caractères : au-delà, son lien disparaît (chaîne vide, voir shareHtml).
 */
export function withFragment(links: ShareLinks, fragment: string): ShareLinks {
	const withHash = (url: string) => `${url}#${fragment}`;
	const param = (intent: string, name: string) => {
		const url = new URL(intent);
		url.searchParams.set(name, withHash(url.searchParams.get(name) ?? ''));
		return url.toString();
	};
	const natif = withHash(links.natif);
	const bluesky = new URL(links.bluesky);
	const text = bluesky.searchParams.get('text') ?? '';
	const blueskyText = text.replace(/(\S+)$/, (url) => withHash(url));
	bluesky.searchParams.set('text', blueskyText);
	return {
		text: links.text,
		linkedin: param(links.linkedin, 'url'),
		x: param(links.x, 'url'),
		bluesky: [...blueskyText].length <= 300 ? bluesky.toString() : '',
		lien: withHash(links.lien),
		natif,
	};
}
