/**
 * `useStorage` de Nitro : unstorage (la bibliothèque de Nitro, utilisée telle quelle) avec son pilote mémoire.
 * En développement, Nitro monte `data` sur le disque (.data/kv) : ici, il vit aussi en mémoire, pour toute
 * la durée du simulateur (il survit aux modifications de fichiers, pas au rechargement de l'exercice).
 */
import { createStorage, prefixStorage, type Storage } from 'unstorage';
import memory from 'unstorage/drivers/memory';

export function createNitroStorage(): Storage {
	const storage = createStorage({ driver: memory() });
	storage.mount('data', memory());
	// Le cache de Nitro (defineCachedEventHandler…) : sur le disque en développement, en mémoire ici.
	storage.mount('cache', memory());
	return storage;
}

export function useStorageOf(storage: Storage) {
	return (base = '') => (base ? prefixStorage(storage, base) : storage);
}
