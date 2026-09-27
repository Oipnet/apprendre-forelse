/**
 * Ce qui change d'un framework PHP à l'autre et qui est du **code** : le script de la console du projet,
 * des scripts en plus, une autre façon de servir l'aperçu. Le reste — dossiers, caches, masquage,
 * libellés — est déclaré par le moteur (App\Content\Framework\FrameworkProfile) et arrive dans la spec.
 *
 * Ajouter un framework PHP, c'est ajouter une entrée à PHP_FRAMEWORKS, sans toucher au runtime.
 */
import type { PHPRunOptions } from '@php-wasm/universal';
import type { HttpRequest, HttpResponse } from '@forelse/runtime-contract';
import consoleScript from './console.php?raw';
import artisanScript from './artisan.php?raw';
import dockerScript from './docker.php?raw';
import dockerHttpScript from './docker-http.php?raw';

/** Ce que le runtime prête à un framework pour servir l'aperçu. */
export interface PhpPreviewContext {
	/** Préfixe d'URL de l'aperçu (voir EnvironmentSpec.previewBasePath). */
	basePath: string;
	/** L'en-tête Cookie des cookies retenus pour l'aperçu, vide s'il n'y en a pas. */
	cookieHeader: string;
	/** Exécute un script sur l'instance de l'aperçu ; une erreur fatale rend quand même sa page. */
	run(options: PHPRunOptions): Promise<HttpResponse>;
}

export interface PhpFrameworkAdapter {
	/** Le script qui exécute la console du projet, arguments dans $_SERVER['CONSOLE_ARGS']. */
	consoleScript: string;
	/** Fichiers ajoutés à chaque instance PHP, par chemin absolu. */
	extraFiles?: Record<string, string>;
	/** Sert l'aperçu autrement que par public/index.php. */
	request?(request: HttpRequest, url: URL, preview: PhpPreviewContext): Promise<HttpResponse>;
}

const DOCKER_HTTP = '/runner/docker-http.php';

/**
 * Aperçu d'un environnement Docker : l'adresse visitée est « /localhost:8080/chemin ». Le port est retenu
 * pour les liens suivants (/menu), et le simulateur fait répondre le conteneur qui publie ce port.
 */
function dockerAdapter(): PhpFrameworkAdapter {
	let port = 80;
	return {
		consoleScript: dockerScript,
		extraFiles: { [DOCKER_HTTP]: dockerHttpScript },
		request(req, url, preview) {
			let path = url.pathname.slice(preview.basePath.length) || '/';
			const visited = path.match(/^\/(?:localhost|127\.0\.0\.1)?:(\d+)(\/.*)?$/);
			if (visited) {
				port = Number(visited[1]);
				path = visited[2] ?? '/';
			}
			const cookieHeader = preview.cookieHeader;
			return preview.run({
				scriptPath: DOCKER_HTTP,
				method: req.method as never,
				body: req.body,
				$_SERVER: {
					DOCKER_HTTP: JSON.stringify({
						method: req.method,
						port,
						uri: path + url.search,
						base: preview.basePath,
						headers: { ...req.headers, host: `localhost:${port}`, ...(cookieHeader ? { cookie: cookieHeader } : {}) },
					}),
				},
			});
		},
	};
}

/** La console d'un projet Symfony : bin/console. C'est aussi celle d'un framework PHP que la table ne connaît pas. */
export const defaultPhpFramework = (): PhpFrameworkAdapter => ({ consoleScript });

/** Les frameworks PHP, par identifiant de profil ; une fabrique par projet, pour que l'état ne se partage pas. */
export const PHP_FRAMEWORKS: Record<string, () => PhpFrameworkAdapter> = {
	symfony: defaultPhpFramework,
	laravel: () => ({ consoleScript: artisanScript }),
	docker: dockerAdapter,
};
