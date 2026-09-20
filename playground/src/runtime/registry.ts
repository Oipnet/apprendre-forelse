import type { Runtime, RuntimeManifest } from '@forelse/runtime-contract';

/**
 * Les runtimes du navigateur, par identifiant.
 *
 * Un **runtime**, c'est une façon d'exécuter un projet : PHP compilé en WebAssembly (`php-wasm`, qui
 * sert Symfony, Laravel et le simulateur Docker) ou le simulateur Nuxt (`nuxt-sim`). Le profil du
 * framework dit lequel employer (`FrameworkProfile::$runtime`, côté moteur) ; cette table dit comment
 * le construire.
 *
 * C'est le point d'extension : les manifestes viennent des paquets installés, découverts au build
 * (voir discover-runtimes.ts), et le moteur n'en nomme aucun. Le navigateur reste un bundle, donc
 * ajouter un runtime demandera toujours de reconstruire le playground — mais rien à y modifier.
 */
const registrations = new Map<string, RuntimeManifest>();

export function registerRuntime(registration: RuntimeManifest): void {
	registrations.set(registration.id, registration);
}

export function knownRuntimes(): string[] {
	return [...registrations.keys()].sort();
}

function find(id: string): RuntimeManifest {
	const registration = registrations.get(id);
	// Un environnement qui demande un runtime absent du bundle ne doit pas échouer plus loin, dans un
	// worker, avec un message incompréhensible : on le dit ici, et on dit ce qu'on sait faire.
	if (!registration) {
		throw new Error(`Runtime « ${id} » inconnu de ce playground (connus : ${knownRuntimes().join(', ') || 'aucun'}).`);
	}
	return registration;
}

export function createRuntime(id: string): Runtime {
	return find(id).create();
}

export function runtimeLabel(id: string): string {
	return registrations.get(id)?.label ?? id;
}
