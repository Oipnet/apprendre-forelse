/** Outils réservés à Node (tests, content:check) : le simulateur lui-même n'utilise jamais le disque. */
import { readdirSync, readFileSync } from 'node:fs';
import { join, relative } from 'node:path';

const IGNORED = new Set(['node_modules', '.nuxt', '.output', '.data', '.git']);

/** Fichiers texte d'un projet Nuxt, par chemin relatif (server/api/…). */
export function loadProject(directory: string): Record<string, string> {
	const files: Record<string, string> = {};
	const walk = (current: string) => {
		for (const entry of readdirSync(current, { withFileTypes: true })) {
			if (IGNORED.has(entry.name)) continue;
			const path = join(current, entry.name);
			if (entry.isDirectory()) walk(path);
			else files[relative(directory, path)] = readFileSync(path, 'utf8');
		}
	};
	walk(directory);
	return files;
}

export { buildClientBundle } from './client-bundle.ts';
