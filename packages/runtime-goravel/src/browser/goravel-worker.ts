/**
 * Worker Goravel : le « serveur » de l'aperçu des parcours Goravel. Il télécharge le binaire (une fois, mis en
 * cache par le navigateur), tient les fichiers du projet et relance une instance quand ils changent.
 */
import { unzipSync } from 'fflate';
import wasmUrl from 'virtual:goravel-wasm';
import { serveRuntime, type BootProgress, type EnvironmentSpec, type HttpRequest, type HttpResponse, type Runtime } from '@forelse/runtime-contract';
import { GoravelProject } from '../project.ts';

let project: GoravelProject | undefined;

function ready(): GoravelProject {
	if (!project) throw new Error('Le runtime Goravel n\'a pas démarré.');
	return project;
}

/** Le binaire, lu par morceaux pour donner la progression du téléchargement. */
async function download(url: string, onProgress?: (p: BootProgress) => void): Promise<Uint8Array> {
	const response = await fetch(url);
	if (!response.ok || !response.body) throw new Error(`Binaire Goravel introuvable (HTTP ${response.status}).`);
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
		onProgress?.({ step: 'download', ratio: total ? received / total : null, label: `Téléchargement de Goravel (${(received / 1e6).toFixed(0)} Mo)` });
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
		if (!wasmUrl) throw new Error('Le binaire Goravel n\'a pas été construit (npm run build:wasm, dans packages/runtime-goravel).');
		onProgress?.({ step: 'download', ratio: null, label: 'Téléchargement de Goravel' });
		const [binary, archive] = await Promise.all([
			download(wasmUrl, onProgress),
			fetch(env.archiveUrl).then((r) => {
				if (!r.ok) throw new Error(`Archive du projet introuvable (HTTP ${r.status}).`);
				return r.arrayBuffer();
			}),
		]);
		onProgress?.({ step: 'unpack', ratio: null, label: 'Décompression du projet Goravel' });
		const files = Object.fromEntries(Object.entries(unzipSync(new Uint8Array(archive))).filter(([path]) => !path.endsWith('/')));
		const module = await WebAssembly.compile(binary as Uint8Array<ArrayBuffer>);
		onProgress?.({ step: 'boot', ratio: null, label: 'Démarrage de Goravel' });
		project = new GoravelProject(module, files, { basePath: env.previewBasePath });
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
