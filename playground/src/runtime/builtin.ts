import discovered from 'virtual:forelse-runtimes';
import phpWasm from './php-wasm';
import { registerRuntime } from './registry';

/**
 * Les runtimes disponibles : celui du moteur, et ceux qu'apportent les paquets installés.
 *
 * `virtual:forelse-runtimes` est construit au build à partir des dépendances qui déclarent un champ
 * `forelse` (voir discover-runtimes.ts). Cette liste ne se modifie donc jamais : installer un paquet
 * de runtime suffit.
 */
for (const manifest of [phpWasm, ...discovered]) registerRuntime(manifest);
