<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Http;

use Forelse\DockerSim\Fs\DiskFs;
use Forelse\DockerSim\Fs\Path;
use Forelse\DockerSim\Http\Nginx\ConfigSnapshot;
use Forelse\DockerSim\Http\Nginx\NginxConfig;
use Forelse\DockerSim\State\Container;

/**
 * nginx : choix du bloc server et de la location, root/index, try_files, return, proxy_pass et
 * fastcgi_pass vers un conteneur php-fpm (où le script doit exister : « File not found. » sinon).
 */
final class NginxServer
{
    public const SIGNATURE = 'nginx/1.29.1';

    public function __construct(private readonly ServerContext $context)
    {
    }

    public function handle(Container $container, HttpRequest $request, int $port): HttpResponse
    {
        $fs = $this->context->fs($container);
        // nginx sert avec la configuration lue au démarrage (ou au dernier reload), pas celle du disque.
        $snapshot = $container->processOptions['nginxConfig'] ?? null;
        $config = NginxConfig::load(\is_array($snapshot) ? new ConfigSnapshot($fs, $snapshot) : $fs);
        $server = $config->serverFor($port, explode(':', $request->headers['host'] ?? $request->host)[0]);
        if ($server === null) {
            return HttpResponse::failure('refused', sprintf('nginx (%s) n\'écoute pas sur le port %d', $container->name, $port));
        }
        $response = $this->process($container, $fs, $server, $request, 0);
        $response->headers['Server'] ??= self::SIGNATURE;
        $this->context->log($container, StaticFiles::accessLog($request, $response->status, \strlen($response->body), 'nginx'));

        return $response;
    }

    private function process(Container $container, DiskFs $fs, array $server, HttpRequest $request, int $depth): HttpResponse
    {
        if ($depth > 10) {
            $this->context->log($container, sprintf('%s [error] 29#29: *1 rewrite or internal redirection cycle while internally redirecting to "%s"', gmdate('Y/m/d H:i:s'), $request->path));

            return HttpResponse::page(500, ErrorPages::nginx(500), self::SIGNATURE);
        }
        $location = $this->match($server['locations'], $request->path);
        $directives = $location['directives'] ?? [];
        $root = $directives['root'][0][0] ?? $server['root'] ?? '/etc/nginx/html';
        $index = $directives['index'][0] ?? $server['index'];
        $trace = [sprintf('nginx (%s) : location %s', $container->name, $location !== null ? trim($location['modifier'].' '.$location['pattern']) : '(aucune)')];

        if (!$this->allowed($location['rules'] ?? [], $server['rules'] ?? [], $request->clientIp)) {
            $this->context->log($container, sprintf('%s [error] 29#29: *1 access forbidden by rule, client: %s, server: %s, request: "%s %s HTTP/1.1", host: "%s"', gmdate('Y/m/d H:i:s'), $request->clientIp, $server['serverName'][0] ?? 'localhost', $request->method, $request->requestUri(), $request->headers['host'] ?? $request->host));

            return HttpResponse::page(403, ErrorPages::nginx(403), self::SIGNATURE, [...$trace, 'deny : accès refusé par une règle']);
        }

        // Un « return » posé dans le bloc server s'exécute avant même le choix de la location.
        if (isset($server['directives']['return']) || isset($directives['return'])) {
            $args = ($server['directives']['return'] ?? $directives['return'])[0];
            $code = (int) $args[0];
            $value = str_replace(['$request_uri', '$uri', '$host', '$scheme'], [$request->uri(), $request->path, $request->host, 'http'], $args[1] ?? '');

            return $code >= 300 && $code < 400 ? new HttpResponse($code, ['Location' => $value], '', null, $trace) : new HttpResponse($code, ['Content-Type' => 'text/plain'], $value, null, $trace);
        }
        if (isset($directives['proxy_pass'])) {
            return $this->proxy($container, $directives['proxy_pass'][0][0], $request, $trace);
        }
        if (isset($directives['fastcgi_pass'])) {
            // Comme nginx : les fastcgi_param du niveau supérieur ne sont hérités que si la location n'en pose aucun.
            $directives['fastcgi_param'] ??= $server['directives']['fastcgi_param'] ?? [];

            return $this->fastcgi($container, $fs, $directives, $root, $request, $trace);
        }
        if (isset($directives['try_files'])) {
            $candidates = $directives['try_files'][0];
            $fallback = array_pop($candidates);
            foreach ($candidates as $candidate) {
                $uri = str_replace(['$uri', '$query_string', '$args', '$is_args'], [$request->path, $request->query, $request->query, $request->query !== '' ? '?' : ''], $candidate);
                $file = rtrim($root, '/').Path::normalize(explode('?', $uri)[0]);
                if (str_ends_with($candidate, '/') ? $fs->isDir($file) : $fs->isFile($file)) {
                    if (str_ends_with($file, '.php') && $this->hasPhpLocation($server)) {
                        return $this->process($container, $fs, $server, $request->withPath(Path::normalize(explode('?', $uri)[0])), $depth + 1);
                    }
                    if ($fs->isDir($file)) {
                        return $this->directory($container, $fs, $server, $root, $index, $file, $request, $depth, $trace);
                    }

                    return $this->static($fs, $file, $trace);
                }
            }
            if (str_starts_with($fallback, '=')) {
                $code = (int) substr($fallback, 1);

                return HttpResponse::page($code, ErrorPages::nginx($code), self::SIGNATURE, [...$trace, 'try_files : aucun fichier, '.$fallback]);
            }
            $uri = str_replace(['$uri', '$query_string', '$args', '$is_args'], [$request->path, $request->query, $request->query, $request->query !== '' ? '?' : ''], $fallback);
            // Le repli est une redirection interne : sans « ? » dans la cible, nginx ne garde pas les arguments.
            [$newPath, $newQuery] = array_pad(explode('?', $uri, 2), 2, '');

            return $this->process($container, $fs, $server, $request->withPath(Path::normalize($newPath), $newQuery), $depth + 1);
        }
        $file = rtrim($root, '/').Path::normalize($request->path);
        if ($fs->isDir($file)) {
            return $this->directory($container, $fs, $server, $root, $index, $file, $request, $depth, $trace);
        }
        if ($fs->isFile($file)) {
            return $this->static($fs, $file, $trace);
        }
        $this->context->log($container, sprintf('%s [error] 29#29: *1 open() "%s" failed (2: No such file or directory), client: %s, server: localhost, request: "%s %s HTTP/1.1"', gmdate('Y/m/d H:i:s'), $file, $request->clientIp, $request->method, $request->requestUri()));

        return HttpResponse::page(404, ErrorPages::nginx(404), self::SIGNATURE, [...$trace, 'fichier introuvable : '.$file]);
    }

    private function directory(Container $container, DiskFs $fs, array $server, string $root, array $index, string $dir, HttpRequest $request, int $depth, array $trace): HttpResponse
    {
        foreach ($index as $name) {
            if ($fs->isFile(rtrim($dir, '/').'/'.$name)) {
                $path = rtrim($request->path, '/').'/'.$name;
                if (str_ends_with($name, '.php') && $this->hasPhpLocation($server)) {
                    return $this->process($container, $fs, $server, $request->withPath($path), $depth + 1);
                }

                return $this->static($fs, rtrim($dir, '/').'/'.$name, $trace);
            }
        }
        $this->context->log($container, sprintf('%s [error] 29#29: *1 directory index of "%s/" is forbidden, client: %s', gmdate('Y/m/d H:i:s'), rtrim($dir, '/'), $request->clientIp));

        return HttpResponse::page(403, ErrorPages::nginx(403), self::SIGNATURE, [...$trace, 'pas de fichier index dans '.$dir]);
    }

    private function static(DiskFs $fs, string $file, array $trace): HttpResponse
    {
        $response = StaticFiles::serve($fs, $file, self::SIGNATURE);
        $response->trace = [...$trace, 'fichier statique : '.$file.(str_ends_with($file, '.php') ? ' (servi tel quel : aucune location ne le passe à PHP)' : '')];

        return $response;
    }

    /**
     * allow / deny, dans l'ordre du fichier : la première règle qui concerne le client décide. Les
     * règles de la location remplacent celles du bloc server ; sans règle, tout est permis.
     *
     * @param list<array{0: string, 1: string}> $locationRules
     * @param list<array{0: string, 1: string}> $serverRules
     */
    private function allowed(array $locationRules, array $serverRules, string $clientIp): bool
    {
        foreach ($locationRules !== [] ? $locationRules : $serverRules as [$action, $who]) {
            if ($who === 'all' || $who === $clientIp || $this->inRange($clientIp, $who)) {
                return $action === 'allow';
            }
        }

        return true;
    }

    private function inRange(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/') || !filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
            return false;
        }
        [$network, $bits] = explode('/', $cidr, 2);
        $mask = -1 << (32 - (int) $bits);

        return (ip2long($ip) & $mask) === ((int) ip2long($network) & $mask);
    }

    private function hasPhpLocation(array $server): bool
    {
        foreach ($server['locations'] as $location) {
            if (isset($location['directives']['fastcgi_pass']) || isset($location['directives']['proxy_pass'])) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string,mixed>> $locations */
    private function match(array $locations, string $path): ?array
    {
        $prefix = null;
        foreach ($locations as $location) {
            if ($location['modifier'] === '=' && $location['pattern'] === $path) {
                return $location;
            }
        }
        foreach ($locations as $location) {
            if (\in_array($location['modifier'], ['', '^~'], true) && str_starts_with($path, $location['pattern']) && ($prefix === null || \strlen($location['pattern']) > \strlen($prefix['pattern']))) {
                $prefix = $location;
            }
        }
        if ($prefix !== null && $prefix['modifier'] === '^~') {
            return $prefix;
        }
        foreach ($locations as $location) {
            if (\in_array($location['modifier'], ['~', '~*'], true) && @preg_match('#'.str_replace('#', '\#', $location['pattern']).'#'.($location['modifier'] === '~*' ? 'i' : ''), $path)) {
                return $location;
            }
        }

        return $prefix;
    }

    private function proxy(Container $container, string $target, HttpRequest $request, array $trace): HttpResponse
    {
        $url = rtrim($target, '/');
        $path = parse_url($url, PHP_URL_PATH);
        $full = $path !== null && $path !== '' ? preg_replace('#^(https?://[^/]+).*$#', '$1', $url).$path.$request->uri() : $url.$request->uri();
        $response = $this->context->network($container)->http($request->method, $full, ['host' => $request->headers['host'] ?? $request->host, 'x-forwarded-for' => $request->clientIp] + $request->headers, $request->body);
        if (isset($response['error'])) {
            $this->context->log($container, sprintf('%s [error] 29#29: *1 connect() failed (111: Connection refused) while connecting to upstream, client: %s, upstream: "%s"', gmdate('Y/m/d H:i:s'), $request->clientIp, $target));

            return HttpResponse::page(502, ErrorPages::nginx(502), self::SIGNATURE, [...$trace, 'proxy_pass '.$target.' : '.$response['message']]);
        }

        return new HttpResponse($response['status'], $response['headers'], $response['body'], null, [...$trace, 'proxy_pass '.$target]);
    }

    private function fastcgi(Container $container, DiskFs $fs, array $directives, string $root, HttpRequest $request, array $trace): HttpResponse
    {
        $target = $directives['fastcgi_pass'][0][0] ?? '';
        [$host, $port] = array_pad(explode(':', $target, 2), 2, '9000');
        [$upstream, $process, $state] = $this->context->upstream($container, $host, (int) $port);
        if ($upstream === null || $state !== 'open') {
            $this->context->log($container, sprintf('%s [error] 29#29: *1 connect() failed (111: Connection refused) while connecting to upstream, client: %s, server: localhost, request: "%s %s HTTP/1.1", upstream: "fastcgi://%s"', gmdate('Y/m/d H:i:s'), $request->clientIp, $request->method, $request->requestUri(), $target));

            return HttpResponse::page(502, ErrorPages::nginx(502), self::SIGNATURE, [...$trace, sprintf('fastcgi_pass %s : %s', $target, $state === 'unresolved' ? 'nom inconnu' : 'connexion refusée')]);
        }
        if ($process !== 'php-fpm') {
            $this->context->log($container, sprintf('%s [error] 29#29: *1 upstream sent unsupported FastCGI protocol version: 72 while reading response header from upstream, upstream: "fastcgi://%s"', gmdate('Y/m/d H:i:s'), $target));

            return HttpResponse::page(502, ErrorPages::nginx(502), self::SIGNATURE, [...$trace, sprintf('fastcgi_pass %s : %s ne parle pas FastCGI', $target, $process ?? 'ce processus')]);
        }
        $scriptName = $request->path;
        $params = [];
        foreach ($directives['fastcgi_param'] ?? [] as $args) {
            $params[$args[0]] = $args[1] ?? '';
        }
        $scriptFilename = isset($params['SCRIPT_FILENAME']) ? str_replace(['$document_root', '$realpath_root', '$fastcgi_script_name', '$request_filename'], [$root, $root, $scriptName, rtrim($root, '/').$scriptName], $params['SCRIPT_FILENAME']) : '';
        $phpFs = $this->context->fs($upstream);
        if ($scriptFilename === '' || !$phpFs->isFile($scriptFilename)) {
            $this->context->log($container, sprintf('%s [error] 29#29: *1 FastCGI sent in stderr: "Primary script unknown" while reading response header from upstream, client: %s, request: "%s %s HTTP/1.1", upstream: "fastcgi://%s"', gmdate('Y/m/d H:i:s'), $request->clientIp, $request->method, $request->requestUri(), $target));
            $this->context->log($upstream, sprintf('%s - %s "%s %s" 404', $request->clientIp, gmdate('d/M/Y:H:i:s O'), $request->method, $request->uri()));

            return new HttpResponse(404, ['Content-Type' => 'text/html; charset=UTF-8', 'Server' => self::SIGNATURE], "File not found.\n", null, [...$trace, $scriptFilename === '' ? 'fastcgi_param SCRIPT_FILENAME absent : php-fpm ne sait pas quel script lancer' : sprintf('php-fpm (%s) : %s n\'existe pas dans ce conteneur', $upstream->name, $scriptFilename)]);
        }
        $response = $this->context->php()->serve($upstream, $scriptFilename, $root, $request, $scriptName, ['SERVER_SOFTWARE' => self::SIGNATURE]);
        $response->trace = [...$trace, sprintf('fastcgi_pass %s → php-fpm (%s) : %s', $target, $upstream->name, $scriptFilename), ...$response->trace];
        $this->context->log($upstream, sprintf('%s -  %s "%s %s" %d', $request->clientIp, gmdate('d/M/Y:H:i:s O'), $request->method, $request->uri(), $response->status));
        foreach ($response->trace as $line) {
            if (str_starts_with($line, 'PHP ')) {
                $this->context->log($upstream, sprintf('[%s] WARNING: [pool www] child 7 said into stderr: "%s"', gmdate('d-M-Y H:i:s'), $this->context->php()->translatePaths($upstream, $line)));
            }
        }
        $response->headers['Content-Type'] ??= 'text/html; charset=UTF-8';

        return $response;
    }
}
