import { describe, expect, it } from 'vitest';
import { apresDemarrage } from '../src/app/attente';

describe('apresDemarrage', () => {
	it('retient les appels jusqu\'au démarrage, puis les passe dans l\'ordre', async () => {
		const appels: string[] = [];
		const runtime = {
			async writeFiles(files: Record<string, string>) {
				appels.push(`écrit ${Object.keys(files).join(',')}`);
			},
			async readFile(path: string) {
				return `contenu de ${path}`;
			},
		};
		let demarrer!: () => void;
		const pret = new Promise<void>((resolve) => (demarrer = resolve)).then(() => {
			appels.push('démarré');
		});
		const vue = apresDemarrage(runtime, pret);
		const ecrit = vue.writeFiles({ 'src/A.php': '<?php' });
		const lu = vue.readFile('src/A.php');
		await Promise.resolve();
		expect(appels).toEqual([]);
		demarrer();
		await ecrit;
		expect(await lu).toBe('contenu de src/A.php');
		expect(appels).toEqual(['démarré', 'écrit src/A.php']);
	});

	it('laisse absente une méthode facultative, et fait échouer les appels si le démarrage échoue', async () => {
		const runtime: { request(): Promise<string>; runCommand?: () => Promise<void> } = { request: async () => 'ok' };
		const pret = Promise.reject(new Error('PHP absent'));
		pret.catch(() => {});
		const vue = apresDemarrage(runtime, pret);
		expect(vue.runCommand).toBeUndefined();
		await expect(vue.request()).rejects.toThrow('PHP absent');
	});
});
