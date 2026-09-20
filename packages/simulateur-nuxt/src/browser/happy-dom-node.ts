/**
 * happy-dom attend l'environnement de Node : ces globales manquent dans un Web Worker. Elles sont posées
 * avant son chargement (voir l'environnement de test `nuxt` du worker Nuxt) ; le navigateur fournit le reste.
 */
export function prepareHappyDom(): void {
	const scope = globalThis as Record<string, any>;
	scope.setImmediate ??= (callback: (...args: unknown[]) => void, ...args: unknown[]) => setTimeout(callback, 0, ...args);
	scope.clearImmediate ??= (handle: number) => clearTimeout(handle);
	scope.process ??= {
		platform: 'linux',
		arch: 'x64',
		env: {},
		versions: {},
		nextTick: (callback: (...args: unknown[]) => void, ...args: unknown[]) => queueMicrotask(() => callback(...args)),
		cwd: () => '/',
		on() {},
		emitWarning() {},
	};
}
