import type { Runtime } from './Runtime';

/**
 * Les runtimes du navigateur, par identifiant.
 *
 * Un **runtime**, c'est une façon d'exécuter un projet : PHP compilé en WebAssembly (`php-wasm`, qui
 * sert Symfony, Laravel et le simulateur Docker) ou le simulateur Nuxt (`nuxt-sim`). Le profil du
 * framework dit lequel employer (`FrameworkProfile::$runtime`, côté moteur) ; cette table dit comment
 * le construire.
 *
 * C'est le point d'extension : ajouter un runtime, c'est en enregistrer un ici, et non retoucher un
 * `if` dans Playground.ts. Le navigateur reste un bundle, donc cela demandera toujours de reconstruire
 * le playground — mais plus de toucher au code qui l'orchestre.
 */
export interface RuntimeRegistration {
	/** L'identifiant qu'un profil de framework déclare (`runtime:`). */
	id: string;
	/** Comment on le nomme à l'apprenant quand il refuse de démarrer (« PHP », « le simulateur Nuxt »). */
	label: string;
	create: () => Runtime;
}

const registrations = new Map<string, RuntimeRegistration>();

export function registerRuntime(registration: RuntimeRegistration): void {
	registrations.set(registration.id, registration);
}

export function knownRuntimes(): string[] {
	return [...registrations.keys()].sort();
}

function find(id: string): RuntimeRegistration {
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
