/**
 * Essai php-wasm-builder : ce que les spikes partagent. Les deux runtimes comparés, chacun derrière PhpWasmProject.
 *
 * BUILDER_MJS : le php-node.mjs du build ; ENVS : le dossier des archives (environments/bin/build-env.sh).
 */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';
import { createSpawnHandler } from '@php-wasm/util';
import type { EnvironmentSpec, FrameworkProfile } from '@forelse/runtime-contract';
import { PhpWasmProject, type PhpInstance } from '../../src/runtime/PhpWasmProject.ts';
import { BuilderPhp, type BuilderModule } from '../../src/runtime/builder/BuilderPhp.ts';


export const BUILDER_MJS = process.env.BUILDER_MJS ?? '';
export const ENVS = process.env.ENVS ?? '';

export type Runtime = 'php-wasm' | 'builder';
export const runtimes: Record<Runtime, () => Promise<PhpInstance>> = {
	'php-wasm': async () => {
		const php = new PHP(await loadNodeRuntime('8.4'));
		await php.setSpawnHandler(createSpawnHandler((_command, processApi) => processApi.exit(0)));
		return php;
	},
	builder: async () => {
		const createPhp = (await import(pathToFileURL(BUILDER_MJS).href)).default as () => Promise<BuilderModule>;
		return new BuilderPhp(await createPhp());
	},
};

const base = {
	console: '', consoleExample: '', bootNote: '', unpackLabel: '', testRunner: 'phpunit', testRunnerLabel: 'PHPUnit',
	runtime: 'php-wasm', snippets: [], consoleAliases: {}, rawCommands: [], namespaceRoots: {},
};
export const symfony = {
	...base, id: 'symfony', label: 'Symfony',
	projectDirs: ['src', 'migrations', 'templates', 'config', 'tests', 'translations'], testCaches: ['var/cache/test'], hidden: ['vendor', 'var'],
} as unknown as FrameworkProfile;
export const laravel = {
	...base, id: 'laravel', label: 'Laravel',
	projectDirs: ['app', 'config', 'database', 'resources', 'routes', 'tests'], testCaches: ['storage/framework/views'], hidden: ['vendor', 'storage', 'bootstrap/cache'],
} as unknown as FrameworkProfile;

/** Un projet neuf sur cet environnement, sans le démarrer. */
export function projet(runtime: Runtime) {
	return new PhpWasmProject({ loadPhp: runtimes[runtime], download: async (env) => new Uint8Array(readFileSync(join(ENVS, `${env}.zip`))) });
}

export function spec(env: string, framework: FrameworkProfile): EnvironmentSpec {
	return { id: env, framework, options: { phpVersion: '8.4' }, archiveUrl: env, previewBasePath: '/preview', previewSecure: false };
}
