import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { createRequire } from 'node:module';
import type { RuntimeBuildContribution } from '@forelse/runtime-contract';
import type { Plugin } from 'vite';

/**
 * Les paquets de runtime installés, découverts parmi les dépendances du playground.
 *
 * Un paquet est un runtime s'il déclare un champ `forelse` dans son `package.json` :
 *
 * ```json
 * { "forelse": { "runtime": "./src/browser/forelse.ts", "vite": "./src/node/vite.ts" } }
 * ```
 *
 * `runtime` désigne le module qui exporte le manifeste (identifiant, libellé, fabrique du runtime) ;
 * `vite`, facultatif, celui qui dit ce que le runtime demande au build. **Ajouter un runtime, c'est
 * installer un paquet** : aucun fichier du moteur n'est modifié, pas même une liste — elle se déduit
 * des dépendances.
 *
 * (Les modules de contribution sont chargés ici en TypeScript : Node les lit directement depuis la
 * version 22.18. Un paquet qui viserait un Node plus ancien livrerait du JavaScript.)
 */
export interface RuntimePackage {
	name: string;
	build?: RuntimeBuildContribution;
}

const require = createRequire(import.meta.url);

/**
 * Le `package.json` d'une dépendance, lu sur le disque.
 *
 * Volontairement **sans** passer par `require.resolve(nom + '/package.json')` : un paquet qui déclare
 * un champ `exports` sans y lister `./package.json` — ce qui est courant, et c'était le cas des nôtres
 * — ferait échouer la résolution, et le runtime serait ignoré sans bruit. Le build produirait alors un
 * playground amputé, sans rien signaler.
 */
function packageManifest(name: string): Record<string, unknown> | null {
	for (const base of require.resolve.paths(name) ?? []) {
		const file = join(base, name, 'package.json');
		if (existsSync(file)) return JSON.parse(readFileSync(file, 'utf8')) as Record<string, unknown>;
	}

	return null;
}

export async function discoverRuntimes(): Promise<RuntimePackage[]> {
	const self = JSON.parse(readFileSync(new URL('./package.json', import.meta.url), 'utf8')) as {
		dependencies?: Record<string, string>;
	};
	const found: RuntimePackage[] = [];

	for (const name of Object.keys(self.dependencies ?? {}).sort()) {
		const forelse = packageManifest(name)?.forelse as { runtime?: string; vite?: string } | undefined;
		if (!forelse?.runtime) continue;
		// À partir d'ici le paquet **est** un runtime : un échec est une erreur, pas un paquet à ignorer.
		let build: RuntimeBuildContribution | undefined;
		if (forelse.vite) {
			try {
				build = ((await import(`${name}/vite`)) as { default: RuntimeBuildContribution }).default;
			} catch (cause) {
				throw new Error(`Le runtime « ${name} » déclare « forelse.vite » mais son module est illisible. Vérifiez que son « exports » expose « ./vite ».`, { cause });
			}
		}
		found.push({ name, build });
	}

	// Une ligne, pour qu'un runtime absent du bundle se voie au build plutôt qu'à l'exécution.
	console.log(`[forelse] runtimes découverts : ${found.map((r) => r.name).join(', ') || 'aucun'}`);

	return found;
}

/**
 * Le module virtuel qui livre au playground les manifestes des runtimes installés.
 *
 * Généré plutôt qu'écrit à la main : la liste dépend de ce qui est installé, et personne n'a à la tenir
 * à jour. Le runtime PHP, livré avec le moteur, s'enregistre de son côté (src/runtime/builtin.ts).
 */
export function runtimesVirtualModule(packages: RuntimePackage[]): Plugin {
	const id = 'virtual:forelse-runtimes';
	const code = [
		...packages.map((p, i) => `import m${i} from ${JSON.stringify(`${p.name}/browser`)};`),
		`export default [${packages.map((_, i) => `m${i}`).join(', ')}];`,
	].join('\n');

	return {
		name: 'forelse-runtimes',
		resolveId: (source) => (source === id ? `\0${id}` : null),
		load: (resolved) => (resolved === `\0${id}` ? code : null),
	};
}
