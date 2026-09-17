<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Http\Nginx;

use Forelse\DockerSim\Fs\FileSystem;
use Forelse\DockerSim\Shell\Network;

/**
 * La configuration nginx d'un conteneur : nginx.conf, les include (conf.d/*.conf), les blocs server
 * et location. Assez pour servir une application PHP derrière php-fpm, un site statique, un
 * reverse proxy, et produire les erreurs de démarrage classiques ([emerg]).
 */
final class NginxConfig
{
    /** @var list<array{listen: list<int>, serverName: list<string>, root: ?string, index: list<string>, locations: list<array<string,mixed>>, directives: array<string, list<list<string>>>, file: string}> */
    public array $servers = [];
    private ?string $problem = null;

    private const KNOWN = ['user', 'worker_processes', 'error_log', 'pid', 'events', 'worker_connections', 'http', 'include', 'default_type', 'log_format', 'access_log', 'sendfile', 'tcp_nopush', 'keepalive_timeout', 'gzip', 'gzip_types', 'gzip_vary', 'gzip_min_length', 'server', 'listen', 'server_name', 'root', 'index', 'location', 'try_files', 'fastcgi_pass', 'fastcgi_param', 'fastcgi_index', 'fastcgi_split_path_info', 'fastcgi_buffers', 'fastcgi_buffer_size', 'fastcgi_read_timeout', 'internal', 'return', 'rewrite', 'error_page', 'proxy_pass', 'proxy_set_header', 'proxy_http_version', 'proxy_read_timeout', 'proxy_redirect', 'add_header', 'expires', 'client_max_body_size', 'charset', 'types', 'deny', 'allow', 'autoindex', 'alias', 'server_tokens', 'upstream', 'set', 'if', 'log_not_found', 'resolver', 'real_ip_header', 'set_real_ip_from', 'http2', 'ssl_certificate', 'ssl_certificate_key', 'multi_accept', 'types_hash_max_size', 'etag', 'open_file_cache', 'absolute_redirect', 'port_in_redirect', 'map', 'default', 'hostnames', 'large_client_header_buffers', 'client_body_buffer_size'];

    public static function load(FileSystem $fs, ?Network $network = null, array $env = []): self
    {
        $config = new self();
        $main = $fs->read('/etc/nginx/nginx.conf');
        if ($main === null) {
            $config->problem = 'open() "/etc/nginx/nginx.conf" failed (2: No such file or directory)';

            return $config;
        }
        try {
            $tree = $config->parseText($main, '/etc/nginx/nginx.conf', $fs);
            $config->collect($tree, '/etc/nginx/nginx.conf');
            if ($config->servers === []) {
                // nginx sans bloc server : il écoute quand même sur 80 et répond 404 ? Non : aucun port.
            }
            if ($network !== null) {
                $config->checkUpstreams($network);
            }
        } catch (\RuntimeException $e) {
            $config->problem = $e->getMessage();
        }

        return $config;
    }

    public function problem(): ?string
    {
        return $this->problem;
    }

    /** @return list<int> */
    public function ports(): array
    {
        $ports = [];
        foreach ($this->servers as $server) {
            array_push($ports, ...$server['listen']);
        }

        return array_values(array_unique($ports));
    }

    /** @return array<string,mixed>|null */
    public function serverFor(int $port, string $host): ?array
    {
        $candidates = array_values(array_filter($this->servers, static fn ($s) => \in_array($port, $s['listen'], true)));
        foreach ($candidates as $server) {
            if (\in_array($host, $server['serverName'], true)) {
                return $server;
            }
        }

        return $candidates[0] ?? null;
    }

    // --- Analyse ------------------------------------------------------------------------------

    /** @return list<array{0: string, 1: list<string>, 2: ?list<mixed>, 3: int, 4: string}> [nom, arguments, bloc, ligne, fichier] */
    private function parseText(string $text, string $file, FileSystem $fs, int $depth = 0): array
    {
        $tokens = $this->tokenize($text, $file);
        $position = 0;
        $tree = $this->parseBlock($tokens, $position, $file, $fs, $depth, false);

        return $tree;
    }

    /** @return list<array{0: string, 1: int}> */
    private function tokenize(string $text, string $file): array
    {
        $tokens = [];
        $line = 1;
        $length = \strlen($text);
        for ($i = 0; $i < $length; ++$i) {
            $c = $text[$i];
            if ($c === "\n") {
                ++$line;
                continue;
            }
            if (ctype_space($c)) {
                continue;
            }
            if ($c === '#') {
                while ($i < $length && $text[$i] !== "\n") {
                    ++$i;
                }
                --$i;
                continue;
            }
            if (\in_array($c, ['{', '}', ';'], true)) {
                $tokens[] = [$c, $line];
                continue;
            }
            if ($c === '"' || $c === "'") {
                $end = $i + 1;
                while ($end < $length && $text[$end] !== $c) {
                    if ($text[$end] === "\n") {
                        ++$line;
                    }
                    ++$end;
                }
                $tokens[] = ["\0".substr($text, $i + 1, $end - $i - 1), $line];
                $i = $end;
                continue;
            }
            $start = $i;
            while ($i < $length && !ctype_space($text[$i]) && !\in_array($text[$i], ['{', '}', ';'], true)) {
                ++$i;
            }
            $tokens[] = [substr($text, $start, $i - $start), $line];
            --$i;
        }

        return $tokens;
    }

    /** @param list<array{0: string, 1: int}> $tokens */
    private function parseBlock(array $tokens, int &$position, string $file, FileSystem $fs, int $depth, bool $inBlock, string $parent = ''): array
    {
        $directives = [];
        $current = [];
        $line = 0;
        $count = \count($tokens);
        while ($position < $count) {
            [$token, $tokenLine] = $tokens[$position++];
            if ($token === '}') {
                if (!$inBlock) {
                    throw new \RuntimeException(sprintf('unexpected "}" in %s:%d', $file, $tokenLine));
                }
                if ($current !== []) {
                    throw new \RuntimeException(sprintf('unexpected "}" in %s:%d', $file, $tokenLine));
                }

                return $directives;
            }
            if ($token === ';') {
                if ($current === []) {
                    continue;
                }
                $name = array_shift($current);
                if (!\in_array($parent, ['types', 'map', 'upstream', 'if'], true)) {
                    $this->checkDirective($name, $file, $line);
                }
                if ($name === 'include') {
                    foreach ($this->includeFiles($current[0] ?? '', $fs) as $included) {
                        if ($depth > 5) {
                            break;
                        }
                        array_push($directives, ...$this->parseText((string) $fs->read($included), $included, $fs, $depth + 1));
                    }
                } else {
                    $directives[] = [$name, $current, null, $line, $file];
                }
                $current = [];
                continue;
            }
            if ($token === '{') {
                if ($current === []) {
                    throw new \RuntimeException(sprintf('unexpected "{" in %s:%d', $file, $tokenLine));
                }
                $name = array_shift($current);
                if (!\in_array($parent, ['types', 'map'], true)) {
                    $this->checkDirective($name, $file, $line);
                }
                $block = $this->parseBlock($tokens, $position, $file, $fs, $depth, true, $name);
                $directives[] = [$name, $current, $block, $line, $file];
                $current = [];
                continue;
            }
            if ($current === []) {
                $line = $tokenLine;
            }
            $current[] = str_starts_with($token, "\0") ? substr($token, 1) : $token;
        }
        if ($current !== []) {
            throw new \RuntimeException(sprintf('unexpected end of file, expecting ";" or "}" in %s:%d', $file, $line));
        }
        if ($inBlock) {
            throw new \RuntimeException(sprintf('unexpected end of file, expecting "}" in %s:%d', $file, $tokens[$count - 1][1] ?? 1));
        }

        return $directives;
    }

    private function checkDirective(string $name, string $file, int $line): void
    {
        if (!\in_array($name, self::KNOWN, true) && !str_starts_with($name, 'ssl_') && !str_starts_with($name, 'gzip_') && !str_starts_with($name, 'proxy_') && !str_starts_with($name, 'fastcgi_') && !str_starts_with($name, 'client_') && !str_starts_with($name, 'uwsgi_') && !str_starts_with($name, 'scgi_')) {
            throw new \RuntimeException(sprintf('unknown directive "%s" in %s:%d', $name, $file, $line));
        }
    }

    /** @return list<string> */
    private function includeFiles(string $pattern, FileSystem $fs): array
    {
        if ($pattern === '') {
            return [];
        }
        $pattern = str_starts_with($pattern, '/') ? $pattern : '/etc/nginx/'.$pattern;
        if (!str_contains($pattern, '*')) {
            if ($fs->isFile($pattern)) {
                return [$pattern];
            }
            if (basename($pattern) === 'fastcgi_params' || basename($pattern) === 'mime.types' || basename($pattern) === 'fastcgi.conf') {
                return []; // fournis par l'image (contenu sans importance ici)
            }
            throw new \RuntimeException(sprintf('open() "%s" failed (2: No such file or directory)', $pattern));
        }
        $dir = \dirname($pattern);
        $regex = '#^'.str_replace('\*', '.*', preg_quote(basename($pattern), '#')).'$#';
        $files = [];
        foreach ($fs->list($dir) as $name) {
            if (preg_match($regex, $name) && $fs->isFile($dir.'/'.$name)) {
                $files[] = $dir.'/'.$name;
            }
        }
        sort($files);

        return $files;
    }

    private function collect(array $tree, string $file): void
    {
        foreach ($tree as [$name, $args, $block, $line, $source]) {
            if ($name === 'http' && $block !== null) {
                $this->collect($block, $file);
            } elseif ($name === 'server' && $block !== null) {
                $this->servers[] = $this->server($block, $source);
            }
        }
    }

    private function server(array $block, string $file): array
    {
        $server = ['listen' => [], 'serverName' => [], 'root' => null, 'index' => ['index.html'], 'locations' => [], 'directives' => [], 'rules' => [], 'file' => $file];
        foreach ($block as [$name, $args, $child, $line, $source]) {
            switch ($name) {
                case 'listen':
                    if (preg_match('/(\d+)/', end($args) ?: '', $m) || preg_match('/(\d+)/', $args[0] ?? '', $m)) {
                        $server['listen'][] = (int) $m[1];
                    }
                    break;
                case 'server_name':
                    $server['serverName'] = $args;
                    break;
                case 'root':
                    $server['root'] = $args[0] ?? null;
                    break;
                case 'index':
                    $server['index'] = $args;
                    break;
                case 'location':
                    $server['locations'][] = $this->location($args, $child ?? [], $line, $source);
                    break;
                case 'allow':
                case 'deny':
                    $server['rules'][] = [$name, $args[0] ?? 'all'];
                    $server['directives'][$name][] = $args;
                    break;
                default:
                    $server['directives'][$name][] = $args;
            }
        }
        if ($server['listen'] === []) {
            $server['listen'] = [80];
        }

        return $server;
    }

    private function location(array $args, array $block, int $line, string $file): array
    {
        $modifier = '';
        $pattern = $args[0] ?? '/';
        if (\in_array($pattern, ['=', '~', '~*', '^~'], true)) {
            $modifier = $pattern;
            $pattern = $args[1] ?? '/';
        }
        $location = ['modifier' => $modifier, 'pattern' => $pattern, 'directives' => [], 'lines' => [], 'rules' => [], 'line' => $line, 'file' => $file, 'locations' => []];
        foreach ($block as [$name, $childArgs, $child, $childLine, $source]) {
            if ($name === 'location') {
                $location['locations'][] = $this->location($childArgs, $child ?? [], $childLine, $source);
                continue;
            }
            if ($name === 'allow' || $name === 'deny') {
                $location['rules'][] = [$name, $childArgs[0] ?? 'all'];
            }
            $location['directives'][$name][] = $childArgs;
            // La ligne et le fichier de chaque directive : nginx les cite dans ses erreurs.
            $location['lines'][$name][] = [$childLine, $source];
        }

        return $location;
    }

    /** Au démarrage, nginx résout les hôtes de fastcgi_pass et proxy_pass : un nom inconnu l'arrête. */
    private function checkUpstreams(Network $network): void
    {
        foreach ($this->servers as $server) {
            foreach ($server['locations'] as $location) {
                foreach (['fastcgi_pass', 'proxy_pass'] as $directive) {
                    foreach ($location['directives'][$directive] ?? [] as $i => $args) {
                        $target = preg_replace('#^https?://#', '', $args[0] ?? '') ?? '';
                        $host = explode(':', explode('/', $target)[0])[0];
                        if ($host === '' || str_starts_with($host, '$') || str_starts_with($host, 'unix') || preg_match('/^\d+\.\d+\.\d+\.\d+$/', $host) || $host === 'localhost') {
                            continue;
                        }
                        if ($network->resolve($host) === null) {
                            [$line, $source] = $location['lines'][$directive][$i] ?? [$location['line'] + 1, $location['file']];

                            throw new \RuntimeException(sprintf('host not found in upstream "%s" in %s:%d', explode('/', $target)[0], $source, $line));
                        }
                    }
                }
            }
        }
    }
}
