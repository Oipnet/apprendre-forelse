import { describe, expect, it } from 'vitest';
import { MemFS } from '../src/memfs.ts';

/** L'API à callbacks de Node, telle que Go l'appelle (syscall/fs_js.go). */
const call = <T>(fs: MemFS, method: string, ...args: unknown[]) =>
	new Promise<T>((resolve, reject) => (fs as unknown as Record<string, (...a: unknown[]) => void>)[method](...args, (error: Error | null, result: T) => (error ? reject(error) : resolve(result))));

describe('MemFS', () => {
	it('ouvre, écrit, relit à une position et tronque, comme SQLite le fait', async () => {
		const fs = new MemFS();
		fs.mkdirp('/app');
		const fd = await call<number>(fs, 'open', '/app/base.db', fs.constants.O_RDWR | fs.constants.O_CREAT, 0o644);
		await call(fs, 'write', fd, new TextEncoder().encode('bonjour'), 0, 7, 10);
		const buffer = new Uint8Array(7);
		expect(await call<number>(fs, 'read', fd, buffer, 0, 7, 10)).toBe(7);
		expect(new TextDecoder().decode(buffer)).toBe('bonjour');
		expect((await call<{ size: number }>(fs, 'fstat', fd)).size).toBe(17);
		await call(fs, 'ftruncate', fd, 3);
		expect(fs.readFile('/app/base.db')).toEqual(new Uint8Array(3));
		await call(fs, 'close', fd);
	});

	it('résout les chemins relatifs depuis le dossier courant de Go', async () => {
		const fs = new MemFS();
		fs.writeFile('/go/src/goravel/.env', 'APP_NAME=x');
		fs.cwd = () => '/go/src/goravel';
		const stat = await call<{ isDirectory(): boolean; size: number }>(fs, 'stat', '.env');
		expect(stat.isDirectory()).toBe(false);
		expect(stat.size).toBe(10);
		expect(await call<string[]>(fs, 'readdir', '.')).toEqual(['.env']);
	});

	it('rend les erreurs avec le code que Go traduit (ENOENT, EEXIST, ENOTEMPTY)', async () => {
		const fs = new MemFS();
		fs.writeFile('/a/b.txt', 'x');
		await expect(call(fs, 'stat', '/rien')).rejects.toMatchObject({ code: 'ENOENT' });
		await expect(call(fs, 'mkdir', '/a', 0o755)).rejects.toMatchObject({ code: 'EEXIST' });
		await expect(call(fs, 'rmdir', '/a')).rejects.toMatchObject({ code: 'ENOTEMPTY' });
		await call(fs, 'rename', '/a', '/c');
		expect(fs.files('/c')).toEqual(['b.txt']);
	});

	it('copie indépendante : les tests ne touchent pas les fichiers de l\'aperçu', () => {
		const fs = new MemFS();
		fs.writeFile('/p/x', 'avant');
		const copy = fs.clone();
		copy.writeFile('/p/x', 'après');
		expect(new TextDecoder().decode(fs.readFile('/p/x')!)).toBe('avant');
	});
});
