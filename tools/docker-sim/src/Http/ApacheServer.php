<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Http;

use Forelse\DockerSim\Fs\DiskFs;
use Forelse\DockerSim\Fs\Path;
use Forelse\DockerSim\State\Container;

/**
 * Apache + mod_php des images php:*-apache : VirtualHost et DocumentRoot (variables d'environnement
 * comprises), DirectoryIndex, .htaccess (AllowOverride, IfModule, mod_rewrite, FallbackResource).
 */
final class ApacheServer
{
    public const SIGNATURE = 'Apache/2.4.65 (Debian)';

    public function __construct(private readonly ServerContext $context)
    {
    }

    /**
     * Contenu des fichiers activés (sites-enabled, conf-enabled) : ce sont des liens vers *-available,
     * un sed sur sites-available modifie donc le site activé.
     *
     * @return array<string,string>
     */
    public static function enabled(DiskFs $fs, string $kind): array
    {
        $files = [];
        foreach ($fs->list('/etc/apache2/'.$kind.'-enabled') as $name) {
            $available = '/etc/apache2/'.$kind.'-available/'.$name;
            $files[$name] = (string) ($fs->isFile($available) ? $fs->read($available) : $fs->read('/etc/apache2/'.$kind.'-enabled/'.$name));
        }

        return $files;
    }

    /** Le DocumentRoot et les avertissements de démarrage (AH00111, AH00112). @return array{0: string, 1: list<string>} */
    public function documentRoot(Container $container, int $port = 80): array
    {
        $fs = $this->context->fs($container);
        $warnings = [];
        $root = '/var/www/html';
        foreach (self::enabled($fs, 'sites') as $content) {
            if (preg_match('/<VirtualHost\s+[^>]*:(\d+|\*)\s*>(.*?)<\/VirtualHost>/is', $content, $vhost) && ($vhost[1] === '*' || (int) $vhost[1] === $port)) {
                if (preg_match('/^\s*DocumentRoot\s+"?([^"\s]+)"?/mi', $vhost[2], $m)) {
                    $root = preg_replace_callback('/\$\{(\w+)\}/', static function ($v) use ($container, &$warnings) {
                        if (!isset($container->env[$v[1]])) {
                            $warnings[] = sprintf('[core:warn] [pid 1] AH00111: Config variable ${%s} is not defined', $v[1]);

                            return '${'.$v[1].'}';
                        }

                        return $container->env[$v[1]];
                    }, $m[1]) ?? $m[1];
                }
                break;
            }
        }
        $root = rtrim($root, '/') ?: '/';
        if (!$fs->isDir($root)) {
            $warnings[] = sprintf('AH00112: Warning: DocumentRoot [%s] does not exist', $root);
        }

        return [$root, $warnings];
    }

    public function handle(Container $container, HttpRequest $request, int $port): HttpResponse
    {
        $fs = $this->context->fs($container);
        [$root] = $this->documentRoot($container, $port);
        $response = $this->resolve($container, $fs, $root, $request, $request->port);
        $response->headers['Server'] ??= self::SIGNATURE;
        $this->context->log($container, StaticFiles::accessLog($request, $response->status, \strlen($response->body)));

        return $response;
    }

    private function resolve(Container $container, DiskFs $fs, string $root, HttpRequest $request, int $port): HttpResponse
    {
        $path = Path::normalize($request->path);
        $target = rtrim($root, '/').$path;
        $trace = [sprintf('apache (%s) : DocumentRoot %s', $container->name, $root)];

        // .htaccess du DocumentRoot jusqu'au dossier demandé.
        $htaccess = $this->htaccess($fs, $root, $fs->isDir($target) ? $target : \dirname($target), $container);
        if ($htaccess['error'] !== null) {
            $this->context->log($container, sprintf('[Mon Sep 15 10:00:00.000000 2026] [core:alert] [pid 21] [client %s:52311] %s', $request->clientIp, $htaccess['error']));

            return HttpResponse::page(500, ErrorPages::apache(500, $port), self::SIGNATURE, [...$trace, $htaccess['error']]);
        }

        if ($fs->isFile($target)) {
            return $this->file($container, $fs, $root, $target, $request, $trace);
        }
        if ($fs->isDir($target)) {
            if (!str_ends_with($request->path, '/')) {
                return new HttpResponse(301, ['Location' => $request->path.'/'.($request->query !== '' ? '?'.$request->query : ''), 'Server' => self::SIGNATURE], '', null, $trace);
            }
            foreach (['index.php', 'index.html'] as $index) {
                if ($fs->isFile(rtrim($target, '/').'/'.$index)) {
                    return $this->file($container, $fs, $root, rtrim($target, '/').'/'.$index, $request, $trace);
                }
            }
            if ($htaccess['front'] === null) {
                return HttpResponse::page(403, ErrorPages::apache(403, $port), self::SIGNATURE, [...$trace, 'Options -Indexes : pas de liste de fichiers']);
            }
        }
        if ($htaccess['front'] !== null) {
            $front = rtrim($root, '/').'/'.ltrim($htaccess['front'], '/');
            if ($fs->isFile($front)) {
                return $this->file($container, $fs, $root, $front, $request, [...$trace, 'réécrit vers '.$htaccess['front'].' ('.$htaccess['via'].')']);
            }
        }
        $this->context->log($container, sprintf('[Mon Sep 15 10:00:00.000000 2026] [core:info] [pid 21] [client %s:52311] AH00128: File does not exist: %s', $request->clientIp, $target));

        return HttpResponse::page(404, ErrorPages::apache(404, $port), self::SIGNATURE, [...$trace, 'fichier introuvable : '.$target]);
    }

    private function file(Container $container, DiskFs $fs, string $root, string $file, HttpRequest $request, array $trace): HttpResponse
    {
        if (str_ends_with($file, '.php')) {
            $scriptName = substr($file, \strlen(rtrim($root, '/'))) ?: '/index.php';
            $response = $this->context->php()->serve($container, $file, $root, $request, $scriptName, ['SERVER_SOFTWARE' => self::SIGNATURE]);
            $response->trace = [...$trace, 'script PHP : '.$file, ...$response->trace];
            foreach ($response->trace as $line) {
                if (str_starts_with($line, 'PHP ')) {
                    $this->context->log($container, sprintf('[Mon Sep 15 10:00:00.000000 2026] [php:warn] [pid 21] [client %s:52311] %s', $request->clientIp, $this->context->php()->translatePaths($container, $line)));
                }
            }
            // Erreur fatale sans affichage des erreurs : Apache sert sa propre page 500.
            if ($response->status === 500 && trim($response->body) === '') {
                $response->body = ErrorPages::apache(500, $request->port);
            }
            $response->headers['Content-Type'] ??= 'text/html; charset=UTF-8';
            $response->headers['X-Powered-By'] ??= 'PHP/'.($container->env['PHP_VERSION'] ?? '8.4.11');
            if ($this->exposesPhp($fs) === false) {
                unset($response->headers['X-Powered-By']);
            }

            return $response;
        }
        $response = StaticFiles::serve($fs, $file, self::SIGNATURE);
        $response->trace = [...$trace, 'fichier statique : '.$file];

        return $response;
    }

    /** expose_php se règle dans php.ini comme dans conf.d/*.ini (le dernier lu gagne). */
    private function exposesPhp(DiskFs $fs): bool
    {
        $expose = true;
        foreach (['/usr/local/etc/php/php.ini', ...$fs->files('/usr/local/etc/php/conf.d')] as $fichier) {
            $contenu = $fs->read($fichier);
            if ($contenu !== null && preg_match_all('/^\s*expose_php\s*=\s*"?(\w+)"?/mi', $contenu, $m)) {
                $valeur = strtolower((string) end($m[1]));
                $expose = !\in_array($valeur, ['off', '0', 'false', 'no'], true);
            }
        }

        return $expose;
    }

    /** @return array{error: ?string, front: ?string, via: string} */
    private function htaccess(DiskFs $fs, string $root, string $dir, Container $container): array
    {
        $result = ['error' => null, 'front' => null, 'via' => ''];
        // AllowOverride All n'est accordé que sous /var/www/ (docker-php.conf) ou par un <Directory> explicite.
        $conf = '';
        $conf .= (string) $fs->read('/etc/apache2/apache2.conf');
        $conf .= implode("\n", self::enabled($fs, 'conf'));
        $conf .= implode("\n", self::enabled($fs, 'sites'));
        $allowed = false;
        if (preg_match_all('#<Directory\s+"?([^">]+)"?\s*>(.*?)</Directory>#is', $conf, $blocks, PREG_SET_ORDER)) {
            $best = -1;
            foreach ($blocks as $block) {
                $directory = rtrim(preg_replace_callback('/\$\{(\w+)\}/', static fn ($v) => $container->env[$v[1]] ?? '', $block[1]) ?? $block[1], '/');
                if (($directory === '' || Path::isUnder($root, $directory)) && preg_match('/AllowOverride\s+(\w+)/i', $block[2], $override) && \strlen($directory) >= $best) {
                    $best = \strlen($directory);
                    $allowed = strtolower($override[1]) !== 'none';
                }
            }
        }
        if (!$allowed) {
            return $result;
        }
        $modules = array_map(static fn ($f) => basename($f, '.load'), array_filter($fs->list('/etc/apache2/mods-enabled'), static fn ($f) => str_ends_with($f, '.load')));
        $modules = [...$modules, 'mime', 'dir', 'alias', 'env', 'setenvif', 'negotiation', 'php', 'authz_core', 'deflate', 'filter'];
        $current = rtrim($root, '/');
        $dirs = [$current];
        foreach (explode('/', trim(substr($dir, \strlen($current)), '/')) as $segment) {
            if ($segment !== '') {
                $current .= '/'.$segment;
                $dirs[] = $current;
            }
        }
        foreach ($dirs as $candidate) {
            $content = $fs->read($candidate.'/.htaccess');
            if ($content === null) {
                continue;
            }
            $content = self::applyIfModule($content, $modules);
            if (preg_match('/^\s*(Rewrite\w+)/mi', $content, $m) && !\in_array('rewrite', $modules, true)) {
                $result['error'] = sprintf("%s/.htaccess: Invalid command '%s', perhaps misspelled or defined by a module not included in the server configuration", $candidate, $m[1]);

                return $result;
            }
            if (preg_match('/^\s*FallbackResource\s+(\S+)/mi', $content, $m)) {
                $result['front'] = $m[1];
                $result['via'] = 'FallbackResource';
            }
            if (preg_match('/^\s*RewriteEngine\s+On/mi', $content) && preg_match('/^\s*RewriteRule\s+\S+\s+(?:%\{ENV:BASE\})?\/?(\S*index\.php)/mi', $content, $m)) {
                $result['front'] = '/'.ltrim(str_replace('%{ENV:BASE}', '', $m[1]), '/');
                $result['via'] = 'mod_rewrite';
            }
        }

        return $result;
    }

    /**
     * Ne garde que les blocs <IfModule> dont le module est chargé (et inversement pour <IfModule !x>).
     *
     * @param list<string> $modules
     */
    public static function applyIfModule(string $content, array $modules): string
    {
        for ($i = 0; $i < 5; ++$i) {
            $content = preg_replace_callback('#<IfModule\s+(!?)([\w.]+)\s*>((?:(?!<IfModule).)*?)</IfModule>#is', static function ($m) use ($modules) {
                $name = preg_replace('/^mod_|\.c$|_module$/', '', $m[2]);
                $present = \in_array($name, $modules, true);

                return ($m[1] === '!' ? !$present : $present) ? $m[3] : '';
            }, $content) ?? $content;
        }

        return $content;
    }
}
