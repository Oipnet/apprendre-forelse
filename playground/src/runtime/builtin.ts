import { NuxtRuntime } from './NuxtRuntime';
import { registerRuntime } from './registry';
import { WasmRuntime } from './WasmRuntime';

/**
 * Les runtimes livrés avec le moteur.
 *
 * Importé une fois, au démarrage du playground. Un runtime ajouté s'enregistrerait de la même façon,
 * depuis son propre module.
 */
registerRuntime({ id: 'php-wasm', label: 'PHP', create: () => new WasmRuntime() });
registerRuntime({ id: 'nuxt-sim', label: 'le simulateur Nuxt', create: () => new NuxtRuntime() });
