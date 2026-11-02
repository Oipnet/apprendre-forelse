/// <reference lib="webworker" />
/**
 * Worker PHP : sert un PhpWasmProject (deux instances php-wasm, aperçu et tests) à la page.
 * Ce fichier ne garde que ce qui tient au navigateur : télécharger l'archive, compiler et charger PHP.
 *
 * Deux workers le servent : php-worker.ts, sans réseau (tous les environnements, le bac à sable compris), et
 * php-network-worker.ts, qui laisse le PHP de l'aperçu joindre les API d'IA (parcours IA seulement). Ce sont deux
 * scripts parce que la CSP d'un worker est celle de son script (docker/Caddyfile) : celui qui n'a pas le réseau
 * ne peut rien joindre, quoi que fasse le code chargé dedans.
 */
import { PHP, setPhpIniEntries } from '@php-wasm/universal';
import { createSpawnHandler } from '@php-wasm/util';
import { getPHPLoaderModule, loadWebRuntime } from '@php-wasm/web';
import type { BootProgress } from '@forelse/runtime-contract';
import { serveRuntime } from '@forelse/runtime-contract';
import { m } from '../i18n';
import type { PhpNetwork } from './php-network';
import { PhpWasmProject } from './PhpWasmProject';

async function download(url: string, progress: (p: BootProgress) => void): Promise<Uint8Array> {
	const response = await fetch(url);
	if (!response.ok || !response.body) throw new Error(m.runtime.downloadFailed(url, response.status));
	const total = Number(response.headers.get('content-length')) || null;
	const chunks: Uint8Array[] = [];
	let received = 0;
	const reader = response.body.getReader();
	for (;;) {
		const { done, value } = await reader.read();
		if (done) break;
		chunks.push(value);
		received += value.length;
		progress({ step: 'download', ratio: total ? received / total : null, label: m.runtime.downloading(received / 1e6) });
	}
	const bytes = new Uint8Array(received);
	let offset = 0;
	for (const chunk of chunks) {
		bytes.set(chunk, offset);
		offset += chunk.length;
	}
	return bytes;
}

/**
 * Le binaire PHP (19 Mo) est téléchargé et compilé une seule fois, puis instancié
 * pour chaque instance via le hook Emscripten `instantiateWasm`. Sans cela, l'instance
 * de tests re-télécharge le .wasm et échoue si le serveur n'est plus joignable.
 */
let compiledPhp: Promise<WebAssembly.Module> | undefined;

/**
 * La seule version de PHP embarquée (voir vite.config.ts), celle du serveur. Un environnement qui demande une version
 * antérieure (« php: '8.4' » d'un pack plus ancien) tourne sur celle-ci : PHP 8.5 exécute le code écrit pour 8.4.
 */
const BROWSER_PHP = '8.5';
function compilePhpWasm(version: typeof BROWSER_PHP): Promise<WebAssembly.Module> {
	compiledPhp ??= getPHPLoaderModule(version).then(async ({ dependencyFilename }) => {
		try {
			return await WebAssembly.compileStreaming(fetch(dependencyFilename));
		} catch {
			// Serveur qui ne renvoie pas application/wasm : compilation depuis le buffer.
			const response = await fetch(dependencyFilename);
			if (!response.ok) throw new Error(m.runtime.phpBinaryMissing(dependencyFilename, response.status));
			return WebAssembly.compile(await response.arrayBuffer());
		}
	});
	return compiledPhp;
}

let nextProcessId = 1;

/**
 * Sert le runtime PHP dans ce worker. `network` : de quoi donner le réseau à une instance (php-network-worker.ts) ;
 * absent, un environnement qui en demande échoue au démarrage plutôt que de tourner sans.
 */
export function servePhpWorker(network: PhpNetwork | null = null): void {
	serveRuntime(new PhpWasmProject({ loadPhp: (version, extensions, hosts) => loadPhp(version, extensions, hosts, network), download }), {
		// php-wasm rend des copies neuves (sortie de PHP, fichier lu) : rien ne les retient dans le worker.
		transferResponseBodies: true,
	});
}

async function loadPhp(_requested: string, extensions: string[], hosts: string[], network: PhpNetwork | null): Promise<PHP> {
	if (hosts.length && !network) throw new Error(m.runtime.networkUnavailable);
	const version = BROWSER_PHP;
	const wasm = await compilePhpWasm(version);
	// Emscripten n'offre pas de voie d'échec à instantiateWasm : sans elle, une instanciation refusée (mémoire Wasm
	// indisponible, plausible sur mobile) laissait le démarrage en suspens pour toujours.
	let instantiationFailed!: (error: unknown) => void;
	const failure = new Promise<never>((_, reject) => (instantiationFailed = reject));
	const emscriptenOptions = {
		processId: nextProcessId++,
		instantiateWasm(imports: WebAssembly.Imports, receive: (instance: WebAssembly.Instance, module: WebAssembly.Module) => void) {
			WebAssembly.instantiate(wasm, imports).then((instance) => receive(instance, wasm), instantiationFailed);
			return {};
		},
	};
	const php = new PHP(await Promise.race([loadWebRuntime(version, {
		emscriptenOptions: emscriptenOptions as never,
		// intl : php-wasm télécharge l'extension et ses données ICU (≈ 13 Mo compressés) seulement si l'environnement la demande.
		extensions: extensions as never,
		// Le réseau (parcours IA) : les requêtes HTTPS de PHP deviennent des fetch() du worker, vers ces hôtes seulement.
		...(hosts.length ? { tcpOverFetch: await network!.tcpOverFetch(hosts) } : {}),
	}), failure]));
	// Pas de processus dans le navigateur : une commande externe (le php-cs-fixer que lance MakerBundle
	// après make:entity, par exemple) se termine sans rien faire, au lieu de faire échouer la commande.
	await php.setSpawnHandler(createSpawnHandler((_command, processApi) => processApi.exit(0)));
	// php-wasm démarre en UTC : date() afficherait 09:06 à un apprenant pour qui il est 11:06. PHP suit le fuseau du navigateur.
	await setPhpIniEntries(php, { 'date.timezone': Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC' });
	// PHP doit faire confiance au certificat qui relit ses requêtes HTTPS avant de les confier à fetch().
	if (hosts.length) await network!.trust(php);
	return php;
}
