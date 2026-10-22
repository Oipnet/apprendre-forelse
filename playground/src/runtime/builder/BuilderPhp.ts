/**
 * Essai php-wasm-builder (lyrixx/php-wasm-builder) : une instance de ce PHP, vue comme le projet voit une
 * instance @php-wasm (PhpInstance). Ce PHP n'a que runFile() et le système de fichiers d'Emscripten :
 * run.php joue le serveur web, et la réponse est relue dans /tmp.
 */
import { PHPResponse, type PHPRunOptions } from '@php-wasm/universal';
import type { PhpInstance } from '../PhpWasmProject';
import runScript from './run.php?raw';

/** Ce que createPhp() du build rend (voir « JavaScript API » dans son README). */
export interface BuilderModule {
	FS: EmscriptenFs;
	runFile(file: string, options?: { args?: string[]; stdin?: string | Uint8Array }): Promise<{ exitCode: number; stdout: string; stderr: string }>;
}

interface EmscriptenFs {
	mkdirTree(path: string): void;
	writeFile(path: string, data: string | Uint8Array): void;
	readFile(path: string): Uint8Array;
	readFile(path: string, options: { encoding: 'utf8' }): string;
	readdir(path: string): string[];
	analyzePath(path: string): { exists: boolean };
	stat(path: string): { mode: number };
	isFile(mode: number): boolean;
	isDir(mode: number): boolean;
	unlink(path: string): void;
	rmdir(path: string): void;
}

const RUN = '/runner/builder-run.php';
const STATUS = '/tmp/reponse.json';
const BODY = '/tmp/reponse.corps';
const encoder = new TextEncoder();

function base64(bytes: Uint8Array): string {
	let binary = '';
	for (let i = 0; i < bytes.length; i += 0x8000) binary += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
	return btoa(binary);
}

export class BuilderPhp implements PhpInstance {
	private readonly fs: EmscriptenFs;

	constructor(private readonly module: BuilderModule) {
		this.fs = module.FS;
		this.fs.mkdirTree('/runner');
		this.fs.mkdirTree('/tmp');
		this.fs.writeFile(RUN, runScript);
	}

	mkdir(path: string) {
		this.fs.mkdirTree(path);
	}

	writeFile(path: string, data: string | Uint8Array) {
		this.fs.writeFile(path, data);
	}

	fileExists(path: string) {
		return this.fs.analyzePath(path).exists;
	}

	unlink(path: string) {
		this.fs.unlink(path);
	}

	isFile(path: string) {
		return this.fileExists(path) && this.fs.isFile(this.fs.stat(path).mode);
	}

	isDir(path: string) {
		return this.fileExists(path) && this.fs.isDir(this.fs.stat(path).mode);
	}

	readFileAsText(path: string) {
		return this.fs.readFile(path, { encoding: 'utf8' });
	}

	readFileAsBuffer(path: string) {
		return this.fs.readFile(path);
	}

	listFiles(path: string) {
		return this.fs.readdir(path).filter((name) => name !== '.' && name !== '..');
	}

	rmdir(path: string, options?: { recursive?: boolean }) {
		if (options?.recursive) {
			for (const name of this.listFiles(path)) {
				const child = `${path}/${name}`;
				if (this.isDir(child)) this.rmdir(child, options);
				else this.fs.unlink(child);
			}
		}
		this.fs.rmdir(path);
	}

	async run(options: PHPRunOptions): Promise<PHPResponse> {
		if (!options.scriptPath) throw new Error('php-wasm-builder : seul scriptPath est pris en charge.');
		const http = options.method !== undefined || options.relativeUri !== undefined;
		const body = typeof options.body === 'string' ? encoder.encode(options.body) : options.body;
		const request = {
			script: options.scriptPath,
			server: options.$_SERVER ?? {},
			http: http ? { method: options.method ?? 'GET', uri: options.relativeUri ?? '/', headers: options.headers ?? {}, body: body ? base64(body) : '' } : null,
		};
		for (const file of [STATUS, BODY]) if (this.fileExists(file)) this.fs.unlink(file);

		const { exitCode, stdout, stderr } = await this.module.runFile(RUN, { stdin: JSON.stringify(request) });

		// Ce que le script écrit sur php://stdout (la console) passe avant ce qu'il écrit avec echo, gardé en tampon.
		const written = this.fileExists(BODY) ? this.fs.readFile(BODY) : new Uint8Array();
		const before = encoder.encode(stdout);
		const bytes = new Uint8Array(before.length + written.length);
		bytes.set(before);
		bytes.set(written, before.length);
		const meta: { status: number; headers: string[] } = this.fileExists(STATUS) ? JSON.parse(this.readFileAsText(STATUS)) : { status: 500, headers: [] };
		const headers: Record<string, string[]> = {};
		for (const line of meta.headers) {
			const at = line.indexOf(':');
			const name = line.slice(0, at).trim().toLowerCase();
			(headers[name] ??= []).push(line.slice(at + 1).trim());
		}
		return new PHPResponse(exitCode === 0 ? meta.status : Math.max(meta.status, 500), headers, bytes, stderr, exitCode);
	}
}
