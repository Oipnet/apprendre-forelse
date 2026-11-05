import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import type { RuntimeBuildContribution } from '@forelse/runtime-contract';

/**
 * Ce que ce runtime demande au build du playground (voir `forelse.vite` dans package.json) : le binaire
 * Goravel, en asset. Il se construit à part (go/build.sh, qui demande Go) : absent, le playground se construit
 * quand même, et le runtime le dit à l'apprenant au démarrage.
 */
const binary = fileURLToPath(new URL('../../dist/goravel.wasm', import.meta.url));
const id = 'virtual:goravel-wasm';

export default {
	plugins: () => [
		{
			name: id,
			resolveId: (source: string) => (source === id ? `\0${id}` : null),
			load(this: { addWatchFile: (f: string) => void }, resolved: string) {
				if (resolved !== `\0${id}`) return null;
				if (!existsSync(binary)) return 'export default null;';
				this.addWatchFile(binary);
				return `import url from ${JSON.stringify(`${binary}?url`)};\nexport default url;`;
			},
		},
	],
} satisfies RuntimeBuildContribution;
