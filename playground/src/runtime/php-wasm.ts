import type { RuntimeManifest } from '@forelse/runtime-contract';
import { NetworkWasmRuntime, WasmRuntime } from './WasmRuntime';

/**
 * Le runtime livré avec le moteur : PHP compilé en WebAssembly.
 *
 * Il sert Symfony, Laravel et le simulateur Docker — tout ce dont le profil déclare
 * `runtime: 'php-wasm'`. Il est écrit comme le serait un runtime venu d'un paquet : même manifeste,
 * même enregistrement.
 */
export default {
	id: 'php-wasm',
	label: 'PHP',
	create: () => new WasmRuntime(),
} satisfies RuntimeManifest;

/**
 * Le même runtime, avec le réseau vers les API d'IA. Aucun profil ne le nomme : la page le choisit quand
 * l'environnement déclare `network:` (voir runtimeFor, Playground.ts).
 */
export const phpWasmNetwork = {
	id: 'php-wasm-network',
	label: 'PHP',
	create: () => new NetworkWasmRuntime(),
} satisfies RuntimeManifest;
