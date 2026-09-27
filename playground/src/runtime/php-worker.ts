/// <reference lib="webworker" />
/**
 * Worker PHP : sert un PhpWasmProject (deux instances php-wasm, aperçu et tests) à la page.
 * Ce fichier ne garde que ce qui tient au navigateur : télécharger l'archive, compiler et charger PHP.
 */
import { PHP } from '@php-wasm/universal';
import { createSpawnHandler } from '@php-wasm/util';
import { getPHPLoaderModule, loadWebRuntime } from '@php-wasm/web';
import type { BootProgress } from '@forelse/runtime-contract';
import { serveRuntime } from '@forelse/runtime-contract';
import { PhpWasmProject } from './PhpWasmProject';

async function download(url: string, progress: (p: BootProgress) => void): Promise<Uint8Array> {
	const response = await fetch(url);
	if (!response.ok || !response.body) throw new Error(`Téléchargement impossible : ${url} (${response.status})`);
	const total = Number(response.headers.get('content-length')) || null;
	const chunks: Uint8Array[] = [];
	let received = 0;
	const reader = response.body.getReader();
	for (;;) {
		const { done, value } = await reader.read();
		if (done) break;
		chunks.push(value);
		received += value.length;
		progress({ step: 'download', ratio: total ? received / total : null, label: `Téléchargement de l'environnement (${(received / 1e6).toFixed(1)} Mo)` });
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
function compilePhpWasm(version: '8.4'): Promise<WebAssembly.Module> {
	compiledPhp ??= getPHPLoaderModule(version).then(async ({ dependencyFilename }) => {
		try {
			return await WebAssembly.compileStreaming(fetch(dependencyFilename));
		} catch {
			// Serveur qui ne renvoie pas application/wasm : compilation depuis le buffer.
			const response = await fetch(dependencyFilename);
			if (!response.ok) throw new Error(`Binaire PHP introuvable : ${dependencyFilename} (${response.status})`);
			return WebAssembly.compile(await response.arrayBuffer());
		}
	});
	return compiledPhp;
}

let nextProcessId = 1;
async function loadPhp(requested: string): Promise<PHP> {
	const version = requested as '8.4';
	const wasm = await compilePhpWasm(version);
	const emscriptenOptions = {
		processId: nextProcessId++,
		instantiateWasm(imports: WebAssembly.Imports, receive: (instance: WebAssembly.Instance, module: WebAssembly.Module) => void) {
			WebAssembly.instantiate(wasm, imports).then((instance) => receive(instance, wasm));
			return {};
		},
	};
	const php = new PHP(await loadWebRuntime(version, { emscriptenOptions: emscriptenOptions as never }));
	// Pas de processus dans le navigateur : une commande externe (le php-cs-fixer que lance MakerBundle
	// après make:entity, par exemple) se termine sans rien faire, au lieu de faire échouer la commande.
	await php.setSpawnHandler(createSpawnHandler((_command, processApi) => processApi.exit(0)));
	return php;
}

serveRuntime(new PhpWasmProject({ loadPhp, download }), {
	// php-wasm rend des copies neuves (sortie de PHP, fichier lu) : rien ne les retient dans le worker.
	transferResponseBodies: true,
});
