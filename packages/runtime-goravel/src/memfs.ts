/**
 * Le système de fichiers que voit Goravel : en mémoire, avec l'API à callbacks de Node que le Go compilé en
 * WebAssembly appelle (syscall/fs_js.go lit globalThis.fs). Le même dans le navigateur et sous Node, pour que
 * content:check et le playground voient exactement les mêmes fichiers.
 *
 * Seul ce dont Go se sert est implémenté : ouvrir, lire et écrire (aussi à une position, pour SQLite), stat,
 * dossiers, renommer, supprimer, tronquer. Pas de droits ni de propriétaires : tout appartient à l'apprenant.
 */

type Callback<T = unknown> = (error: NodeError | null, result?: T) => void;

interface FileNode {
	kind: 'file';
	data: Uint8Array;
	size: number;
	mode: number;
	mtimeMs: number;
	ino: number;
}

interface DirNode {
	kind: 'dir';
	mode: number;
	mtimeMs: number;
	ino: number;
}

type Node = FileNode | DirNode;

interface Descriptor {
	path: string;
	append: boolean;
	position: number;
}

export interface NodeError extends Error {
	code: string;
}

const O_WRONLY = 1;
const O_RDWR = 2;
const O_CREAT = 64;
const O_EXCL = 128;
const O_TRUNC = 512;
const O_APPEND = 1024;
const O_DIRECTORY = 65536;
const S_IFREG = 0o100000;
const S_IFDIR = 0o040000;

function fail(code: string, path: string): NodeError {
	return Object.assign(new Error(`${code}: ${path}`), { code });
}

/** Normalise un chemin absolu (/a/./b/../c => /a/c). */
export function normalize(path: string): string {
	const parts: string[] = [];
	for (const part of path.split('/')) {
		if (part === '' || part === '.') continue;
		if (part === '..') parts.pop();
		else parts.push(part);
	}
	return `/${parts.join('/')}`;
}

function parent(path: string): string {
	return normalize(`${path}/..`);
}

export class MemFS {
	private readonly nodes = new Map<string, Node>([['/', { kind: 'dir', mode: 0o755, mtimeMs: Date.now(), ino: 1 }]]);
	private readonly descriptors = new Map<number, Descriptor>();
	private nextFd = 10;
	private nextIno = 2;
	/** Sorties standard et d'erreur de Go (fmt.Println, journaux de Gin, sortie de go test). */
	onOutput: (fd: 1 | 2, text: string) => void = () => {};
	private readonly decoder = new TextDecoder();
	/** Le dossier courant de Go (process.cwd de son instance) : les chemins relatifs partent de là. */
	cwd: () => string = () => '/';

	readonly constants = { O_WRONLY, O_RDWR, O_CREAT, O_TRUNC, O_APPEND, O_EXCL, O_DIRECTORY };

	// --- Côté JavaScript : le projet ------------------------------------------------------------

	writeFile(path: string, content: string | Uint8Array): void {
		path = normalize(path);
		this.mkdirp(parent(path));
		const data = typeof content === 'string' ? new TextEncoder().encode(content) : content;
		const existing = this.nodes.get(path);
		if (existing?.kind === 'dir') throw fail('EISDIR', path);
		this.nodes.set(path, { kind: 'file', data, size: data.length, mode: 0o644, mtimeMs: Date.now(), ino: existing?.ino ?? this.nextIno++ });
	}

	readFile(path: string): Uint8Array | null {
		const node = this.nodes.get(normalize(path));
		return node?.kind === 'file' ? node.data.subarray(0, node.size) : null;
	}

	exists(path: string): boolean {
		return this.nodes.has(normalize(path));
	}

	/** Les fichiers sous un dossier, relatifs à lui. */
	files(root: string): string[] {
		const prefix = `${normalize(root)}/`;
		return [...this.nodes.entries()].filter(([path, node]) => node.kind === 'file' && path.startsWith(prefix)).map(([path]) => path.slice(prefix.length)).sort();
	}

	remove(path: string): void {
		path = normalize(path);
		for (const key of [...this.nodes.keys()]) {
			if (key === path || key.startsWith(`${path}/`)) this.nodes.delete(key);
		}
	}

	mkdirp(path: string): void {
		path = normalize(path);
		if (this.nodes.has(path)) return;
		this.mkdirp(parent(path));
		this.nodes.set(path, { kind: 'dir', mode: 0o755, mtimeMs: Date.now(), ino: this.nextIno++ });
	}

	/** Une copie indépendante (les tests tournent sur une copie : ils ne touchent pas la base de l'aperçu). */
	clone(): MemFS {
		const copy = new MemFS();
		for (const [path, node] of this.nodes) {
			copy.nodes.set(path, node.kind === 'file' ? { ...node, data: node.data.slice(0, node.size) } : { ...node });
		}
		copy.nextIno = this.nextIno;
		return copy;
	}

	// --- Côté Go : l'API de Node, à callbacks ---------------------------------------------------

	writeSync(fd: number, buffer: Uint8Array): number {
		if (fd === 1 || fd === 2) {
			this.onOutput(fd, this.decoder.decode(buffer));
			return buffer.length;
		}
		return this.writeAt(fd, buffer, 0, buffer.length, null);
	}

	open(path: string, flags: number, mode: number, callback: Callback<number>): void {
		this.call(callback, () => {
			path = this.resolve(path);
			let node = this.nodes.get(path);
			if (node && flags & O_CREAT && flags & O_EXCL) throw fail('EEXIST', path);
			if (!node) {
				if (!(flags & O_CREAT)) throw fail('ENOENT', path);
				const dir = this.nodes.get(parent(path));
				if (!dir) throw fail('ENOENT', path);
				if (dir.kind !== 'dir') throw fail('ENOTDIR', path);
				node = { kind: 'file', data: new Uint8Array(0), size: 0, mode: mode & 0o777, mtimeMs: Date.now(), ino: this.nextIno++ };
				this.nodes.set(path, node);
			}
			if (node.kind === 'dir' && flags & (O_WRONLY | O_RDWR)) throw fail('EISDIR', path);
			if (node.kind === 'file' && flags & O_DIRECTORY) throw fail('ENOTDIR', path);
			if (node.kind === 'file' && flags & O_TRUNC) {
				node.size = 0;
				node.mtimeMs = Date.now();
			}
			const fd = this.nextFd++;
			this.descriptors.set(fd, { path, append: Boolean(flags & O_APPEND), position: 0 });
			return fd;
		});
	}

	close(fd: number, callback: Callback): void {
		this.call(callback, () => {
			if (!this.descriptors.delete(fd)) throw fail('EBADF', String(fd));
		});
	}

	read(fd: number, buffer: Uint8Array, offset: number, length: number, position: number | null, callback: Callback<number>): void {
		this.call(callback, () => {
			const descriptor = this.descriptor(fd);
			const node = this.file(descriptor.path);
			const start = position ?? descriptor.position;
			const count = Math.max(0, Math.min(length, node.size - start));
			buffer.set(node.data.subarray(start, start + count), offset);
			if (position === null) descriptor.position += count;
			return count;
		});
	}

	write(fd: number, buffer: Uint8Array, offset: number, length: number, position: number | null, callback: Callback<number>): void {
		this.call(callback, () => {
			if (fd === 1 || fd === 2) return this.writeSync(fd, buffer.subarray(offset, offset + length));
			return this.writeAt(fd, buffer, offset, length, position);
		});
	}

	fstat(fd: number, callback: Callback<Stats>): void {
		this.call(callback, () => {
			if (fd <= 2) return stats({ kind: 'file', data: new Uint8Array(0), size: 0, mode: 0o620, mtimeMs: 0, ino: fd + 100 });
			return stats(this.node(this.descriptor(fd).path));
		});
	}

	stat(path: string, callback: Callback<Stats>): void {
		this.call(callback, () => stats(this.node(this.resolve(path))));
	}

	lstat(path: string, callback: Callback<Stats>): void {
		this.stat(path, callback);
	}

	readdir(path: string, callback: Callback<string[]>): void {
		this.call(callback, () => {
			path = this.resolve(path);
			if (this.node(path).kind !== 'dir') throw fail('ENOTDIR', path);
			const prefix = path === '/' ? '/' : `${path}/`;
			return [...this.nodes.keys()].filter((key) => key !== path && key.startsWith(prefix) && !key.slice(prefix.length).includes('/')).map((key) => key.slice(prefix.length)).sort();
		});
	}

	mkdir(path: string, mode: number, callback: Callback): void {
		this.call(callback, () => {
			path = this.resolve(path);
			if (this.nodes.has(path)) throw fail('EEXIST', path);
			const dir = this.nodes.get(parent(path));
			if (!dir) throw fail('ENOENT', path);
			if (dir.kind !== 'dir') throw fail('ENOTDIR', path);
			this.nodes.set(path, { kind: 'dir', mode: mode & 0o777, mtimeMs: Date.now(), ino: this.nextIno++ });
		});
	}

	rmdir(path: string, callback: Callback): void {
		this.call(callback, () => {
			path = this.resolve(path);
			if (this.node(path).kind !== 'dir') throw fail('ENOTDIR', path);
			if ([...this.nodes.keys()].some((key) => key.startsWith(`${path}/`))) throw fail('ENOTEMPTY', path);
			this.nodes.delete(path);
		});
	}

	unlink(path: string, callback: Callback): void {
		this.call(callback, () => {
			path = this.resolve(path);
			if (this.node(path).kind === 'dir') throw fail('EISDIR', path);
			this.nodes.delete(path);
		});
	}

	rename(from: string, to: string, callback: Callback): void {
		this.call(callback, () => {
			from = this.resolve(from);
			to = this.resolve(to);
			const node = this.node(from);
			if (!this.nodes.has(parent(to))) throw fail('ENOENT', to);
			const moved = [...this.nodes.entries()].filter(([key]) => key === from || key.startsWith(`${from}/`));
			for (const [key] of moved) this.nodes.delete(key);
			for (const [key, value] of moved) this.nodes.set(to + key.slice(from.length), value);
			node.mtimeMs = Date.now();
		});
	}

	truncate(path: string, length: number, callback: Callback): void {
		this.call(callback, () => this.resize(this.file(this.resolve(path)), length));
	}

	ftruncate(fd: number, length: number, callback: Callback): void {
		this.call(callback, () => this.resize(this.file(this.descriptor(fd).path), length));
	}

	fsync(fd: number, callback: Callback): void {
		this.call(callback, () => void this.descriptor(fd));
	}

	utimes(path: string, _atime: number, mtime: number, callback: Callback): void {
		this.call(callback, () => {
			this.node(this.resolve(path)).mtimeMs = mtime * 1000;
		});
	}

	chmod(path: string, mode: number, callback: Callback): void {
		this.call(callback, () => {
			this.node(this.resolve(path)).mode = mode & 0o777;
		});
	}

	fchmod(fd: number, mode: number, callback: Callback): void {
		this.call(callback, () => {
			this.node(this.descriptor(fd).path).mode = mode & 0o777;
		});
	}

	chown(_path: string, _uid: number, _gid: number, callback: Callback): void {
		this.call(callback, () => undefined);
	}

	fchown(_fd: number, _uid: number, _gid: number, callback: Callback): void {
		this.call(callback, () => undefined);
	}

	lchown(_path: string, _uid: number, _gid: number, callback: Callback): void {
		this.call(callback, () => undefined);
	}

	readlink(path: string, callback: Callback): void {
		this.call(callback, () => {
			throw fail('EINVAL', path);
		});
	}

	link(_from: string, to: string, callback: Callback): void {
		this.call(callback, () => {
			throw fail('ENOSYS', to);
		});
	}

	symlink(_target: string, path: string, callback: Callback): void {
		this.call(callback, () => {
			throw fail('ENOSYS', path);
		});
	}

	// --- Interne ---------------------------------------------------------------------------------

	private resolve(path: string): string {
		return normalize(path.startsWith('/') ? path : `${this.cwd()}/${path}`);
	}

	/**
	 * Répond au callback **après** l'appel, comme Node : Go attend la réponse sur un canal, et un callback
	 * appelé pendant l'appel lui-même le réveillerait avant qu'il ne se soit mis à attendre.
	 */
	private call<T>(callback: Callback<T>, action: () => T): void {
		let result: T;
		try {
			result = action();
		} catch (error) {
			queueMicrotask(() => callback(error as NodeError));
			return;
		}
		queueMicrotask(() => callback(null, result));
	}

	private writeAt(fd: number, buffer: Uint8Array, offset: number, length: number, position: number | null): number {
		const descriptor = this.descriptor(fd);
		const node = this.file(descriptor.path);
		const start = descriptor.append ? node.size : (position ?? descriptor.position);
		this.resize(node, Math.max(node.size, start + length));
		node.data.set(buffer.subarray(offset, offset + length), start);
		if (position === null) descriptor.position = start + length;
		return length;
	}

	private resize(node: FileNode, size: number): void {
		if (size > node.data.length) {
			const grown = new Uint8Array(Math.max(size, node.data.length * 2, 4096));
			grown.set(node.data.subarray(0, node.size));
			node.data = grown;
		} else if (size < node.size) {
			node.data.fill(0, size, node.size);
		}
		node.size = size;
		node.mtimeMs = Date.now();
	}

	private descriptor(fd: number): Descriptor {
		const descriptor = this.descriptors.get(fd);
		if (!descriptor) throw fail('EBADF', String(fd));
		return descriptor;
	}

	private node(path: string): Node {
		const node = this.nodes.get(path);
		if (!node) throw fail('ENOENT', path);
		return node;
	}

	private file(path: string): FileNode {
		const node = this.node(path);
		if (node.kind !== 'file') throw fail('EISDIR', path);
		return node;
	}
}

interface Stats {
	dev: number;
	ino: number;
	mode: number;
	nlink: number;
	uid: number;
	gid: number;
	rdev: number;
	size: number;
	blksize: number;
	blocks: number;
	atimeMs: number;
	mtimeMs: number;
	ctimeMs: number;
	isDirectory(): boolean;
}

function stats(node: Node): Stats {
	const size = node.kind === 'file' ? node.size : 4096;
	return {
		dev: 1,
		ino: node.ino,
		mode: (node.kind === 'dir' ? S_IFDIR : S_IFREG) | node.mode,
		nlink: 1,
		uid: 0,
		gid: 0,
		rdev: 0,
		size,
		blksize: 4096,
		blocks: Math.ceil(size / 512),
		atimeMs: node.mtimeMs,
		mtimeMs: node.mtimeMs,
		ctimeMs: node.mtimeMs,
		isDirectory: () => node.kind === 'dir',
	};
}
