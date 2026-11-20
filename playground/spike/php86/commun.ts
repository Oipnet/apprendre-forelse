/**
 * Essai temporaire PHP 8.6 (retiré avant la revue) : les exercices de Pratique sur notre build php-wasm 8.6,
 * installé à la place de @php-wasm/node-8-5 par le workflow. ENVS : le dossier des archives (build-env.sh).
 */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';
import { createSpawnHandler } from '@php-wasm/util';
import type { EnvironmentSpec, FrameworkProfile } from '@forelse/runtime-contract';
import { PhpWasmProject, type PhpInstance } from '../../src/runtime/PhpWasmProject.ts';

export const ENVS = process.env.ENVS ?? '';
let nextProcessId = 1;

async function loadPhp(_version: string, extensions: string[]): Promise<PhpInstance> {
	const php = new PHP(await loadNodeRuntime('8.5', {
		emscriptenOptions: { processId: nextProcessId++ },
		withIntl: extensions.includes('intl'),
	} as never));
	await php.setSpawnHandler(createSpawnHandler((_command, processApi) => processApi.exit(0)));
	return php;
}

const base = {
	console: '', consoleExample: '', bootNote: '', unpackLabel: '', testRunner: 'phpunit', testRunnerLabel: 'PHPUnit',
	runtime: 'php-wasm', snippets: [], consoleAliases: {}, rawCommands: [], namespaceRoots: {},
};
const profils: Record<string, FrameworkProfile> = {
	symfony: { ...base, id: 'symfony', label: 'Symfony', projectDirs: ['src', 'migrations', 'templates', 'config', 'tests', 'translations'], testCaches: ['var/cache/test'], hidden: ['vendor', 'var'] } as unknown as FrameworkProfile,
	laravel: { ...base, id: 'laravel', label: 'Laravel', projectDirs: ['app', 'config', 'database', 'resources', 'routes', 'tests'], testCaches: ['storage/framework/views'], hidden: ['vendor', 'storage', 'bootstrap/cache'] } as unknown as FrameworkProfile,
	php: { ...base, id: 'php', label: 'PHP', console: 'php', projectDirs: ['public', 'src', 'bin', 'templates', 'tests'], testCaches: [], hidden: ['vendor', '.git', 'node_modules', '.phpunit.cache', '.forelse'] } as unknown as FrameworkProfile,
};

export function projet() {
	return new PhpWasmProject({ loadPhp, download: async (env) => new Uint8Array(readFileSync(join(ENVS, `${env}.zip`))) });
}

/** La spec d'un environnement, d'après son environment.yaml (framework hérité de « extends », extensions). */
export function spec(env: string, racine: string): EnvironmentSpec {
	const lire = (id: string): string => readFileSync(join(racine, id, 'environment.yaml'), 'utf8');
	let yaml = lire(env); let framework = ''; let extensions = '';
	for (let id = env, i = 0; id && i < 5; i++) {
		yaml = lire(id);
		framework ||= yaml.match(/^framework:\s*(\S+)/m)?.[1] ?? '';
		extensions ||= yaml.match(/^extensions:\s*\[([^\]]*)\]/m)?.[1] ?? '';
		id = yaml.match(/^extends:\s*(\S+)/m)?.[1] ?? '';
	}
	framework ||= 'symfony';
	return { id: env, framework: profils[framework] ?? profils.symfony, options: { phpVersion: '8.5', phpExtensions: extensions.replace(/\s/g, '') }, archiveUrl: env, previewBasePath: '/preview', previewSecure: false };
}
