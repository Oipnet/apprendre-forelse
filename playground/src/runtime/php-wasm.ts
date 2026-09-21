import type { RuntimeManifest } from '@forelse/runtime-contract';
import { WasmRuntime } from './WasmRuntime';

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
