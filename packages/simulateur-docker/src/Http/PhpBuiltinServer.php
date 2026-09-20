<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Http;

use Forelse\DockerSim\Fs\Path;
use Forelse\DockerSim\State\Container;

/** Le serveur intégré de PHP (php -S adresse:port -t dossier [routeur.php]). */
final class PhpBuiltinServer
{
    public function __construct(private readonly ServerContext $context)
    {
    }

    /** @param array{docroot: string, router: ?string} $options */
    public function handle(Container $container, HttpRequest $request, array $options): HttpResponse
    {
        $fs = $this->context->fs($container);
        $root = rtrim($options['docroot'], '/');
        $path = Path::normalize($request->path);
        $trace = [sprintf('php -S (%s) : docroot %s', $container->name, $root)];
        $version = $container->env['PHP_VERSION'] ?? '8.4.11';
        if ($options['router'] !== null) {
            $response = $this->context->php()->serve($container, $options['router'], $root, $request, $path, ['SERVER_SOFTWARE' => 'PHP/'.$version.' (Development Server)']);
            $response->trace = [...$trace, 'routeur : '.$options['router'], ...$response->trace];
        } elseif ($fs->isFile($root.$path) && !str_ends_with($path, '.php')) {
            $response = StaticFiles::serve($fs, $root.$path, 'PHP/'.$version.' (Development Server)');
        } else {
            $script = null;
            if ($fs->isFile($root.$path)) {
                $script = $root.$path;
            } elseif ($fs->isDir($root.$path) && $fs->isFile(rtrim($root.$path, '/').'/index.php')) {
                $script = rtrim($root.$path, '/').'/index.php';
            } elseif ($fs->isDir($root.$path) && $fs->isFile(rtrim($root.$path, '/').'/index.html')) {
                // Le serveur intégré sert index.html quand il n'y a pas d'index.php.
                return StaticFiles::serve($fs, rtrim($root.$path, '/').'/index.html', 'PHP/'.$version.' (Development Server)');
            } elseif (!str_contains(basename($path), '.') && $fs->isFile($root.'/index.php')) {
                // Sans routeur, une URL sans fichier retombe sur l'index.php du docroot.
                $script = $root.'/index.php';
            }
            if ($script === null) {
                $response = new HttpResponse(404, ['Content-Type' => 'text/html; charset=UTF-8'], ErrorPages::phpServer($path), null, [...$trace, 'introuvable : '.$root.$path]);
            } else {
                $response = $this->context->php()->serve($container, $script, $root, $request, substr($script, \strlen($root)), ['SERVER_SOFTWARE' => 'PHP/'.$version.' (Development Server)']);
                $response->trace = [...$trace, 'script PHP : '.$script, ...$response->trace];
            }
        }
        $response->headers['Host'] ??= $request->host;
        $this->context->log($container, sprintf('[%s] %s:%d [%d]: %s %s', gmdate('D M j H:i:s Y'), $request->clientIp, 50000 + crc32($request->uri()) % 9999, $response->status, $request->method, $request->uri()));

        return $response;
    }
}
