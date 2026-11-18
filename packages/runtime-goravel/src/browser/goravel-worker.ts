/**
 * Worker Goravel : le « serveur » de l'aperçu des parcours Goravel. Il télécharge le binaire (une fois, mis en
 * cache par le navigateur), tient les fichiers du projet et relance une instance quand ils changent.
 */
import { unzipSync } from 'fflate';
import wasmUrl from 'virtual:goravel-wasm';
import { serveRuntime, type BootProgress, type EnvironmentSpec, type HttpRequest, type HttpResponse, type Runtime } from '@forelse/runtime-contract';
import { catalogue, type Messages } from '../i18n/index.ts';
import { GoravelProject } from '../project.ts';

let project: GoravelProject | undefined;
/** Les textes, dans la langue de la page (option « locale » du démarrage) : le français avant le démarrage. */
let m: Messages = catalogue('fr');

function ready(): GoravelProject {
	if (!project) throw new Error(m.boot.notStarted);
	return project;
}

/** Le binaire, lu par morceaux pour donner la progression du téléchargement. */
async function download(url: string, onProgress?: (p: BootProgress) => void): Promise<Uint8Array> {
	const response = await fetch(url);
	if (!response.ok || !response.body) throw new Error(m.boot.binaryMissing(response.status));
	// Content-Length est la taille compressée quand le serveur sert le .br : la proportion n'est alors pas fiable.
	const encoded = response.headers.get('Content-Encoding');
	const total = encoded ? 0 : Number(response.headers.get('Content-Length') ?? 0);
	const chunks: Uint8Array[] = [];
	let received = 0;
	const reader = response.body.getReader();
	for (;;) {
		const { done, value } = await reader.read();
		if (done) break;
		chunks.push(value);
		received += value.length;
		onProgress?.({ step: 'download', ratio: total ? received / total : null, label: m.boot.downloadingSize((received / 1e6).toFixed(0)) });
	}
	const bytes = new Uint8Array(received);
	let offset = 0;
	for (const chunk of chunks) {
		bytes.set(chunk, offset);
		offset += chunk.length;
	}
	return bytes;
}

const api: Runtime = {
	async boot(env: EnvironmentSpec, onProgress?: (p: BootProgress) => void) {
		m = catalogue(env.options?.locale);
		if (!wasmUrl) throw new Error(m.boot.binaryNotBuilt);
		onProgress?.({ step: 'download', ratio: null, label: m.boot.downloading });
		const [binary, archive] = await Promise.all([
			download(wasmUrl, onProgress),
			fetch(env.archiveUrl).then((r) => {
				if (!r.ok) throw new Error(m.boot.archiveMissing(r.status));
				return r.arrayBuffer();
			}),
		]);
		onProgress?.({ step: 'unpack', ratio: null, label: m.boot.unpacking });
		const files = Object.fromEntries(Object.entries(unzipSync(new Uint8Array(archive))).filter(([path]) => !path.endsWith('/')));
		const module = await WebAssembly.compile(binary as Uint8Array<ArrayBuffer>);
		onProgress?.({ step: 'boot', ratio: null, label: m.boot.starting });
		project = new GoravelProject(module, files, { basePath: env.previewBasePath, locale: env.options?.locale });
		await project.start();
	},

	async writeFile(path, content) {
		ready().writeFile(path, content);
	},

	async writeFiles(files) {
		for (const [path, content] of Object.entries(files)) ready().writeFile(path, content);
	},

	async readFile(path) {
		return ready().readFile(path);
	},

	async listFiles() {
		return ready().listFiles();
	},

	async deleteFile(path) {
		ready().deleteFile(path);
	},

	async request(request: HttpRequest): Promise<HttpResponse> {
		return ready().request(request);
	},

	async runTests(grading) {
		return ready().runTests(grading);
	},

	// Pas de runCommand : artisan n'est pas compilé dans le binaire du navigateur.
};

// Les corps de réponse sont des copies neuves (go/main.go les copie hors de la mémoire de Go) : transférables.
serveRuntime(api, { transferResponseBodies: true });
