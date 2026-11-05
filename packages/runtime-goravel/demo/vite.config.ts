/**
 * Démo du runtime dans un vrai navigateur, sans le moteur : le worker, le binaire et l'environnement goravel-1.
 *
 *     environments/bin/build-env.sh goravel-1 packages/runtime-goravel/demo/public
 *     node ../../playground/node_modules/vite/bin/vite.js --config demo/vite.config.ts   (depuis packages/runtime-goravel)
 *
 * Pas d'import de Vite ici : le paquet ne le déclare pas, c'est celui du playground qui sert la démo.
 */
import contribution from '../src/node/vite.ts';

export default {
	root: new URL('.', import.meta.url).pathname,
	server: { fs: { allow: ['../..'] } },
	plugins: contribution.plugins(),
	worker: { format: 'es', plugins: () => contribution.plugins() },
};
