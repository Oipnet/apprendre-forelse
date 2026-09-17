/**
 * Crée un worker module à partir d'une URL obtenue par `import url from './x.ts?worker&url'`.
 *
 * En dev, le script est servi par Vite (autre origine que la page Symfony), et un Worker
 * doit être de même origine : on passe par un worker `blob:` qui importe le module.
 * En production, tout est servi par Symfony, sur une seule origine.
 */
export function createModuleWorker(url: string, name?: string): Worker {
	const absolute = new URL(url, import.meta.url);
	if (absolute.origin === location.origin) return new Worker(absolute, { type: 'module', name });
	const shim = new Blob([`import ${JSON.stringify(absolute.href)};`], { type: 'text/javascript' });
	return new Worker(URL.createObjectURL(shim), { type: 'module', name });
}
