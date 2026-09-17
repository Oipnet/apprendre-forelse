<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Shell\Command;

use Forelse\DockerSim\Shell\Interpreter;
use Forelse\DockerSim\Shell\Machine;
use Forelse\DockerSim\Shell\Result;

/**
 * Le réseau vu depuis un conteneur : curl, wget, ping, nc, getent, et les clients des bases de données
 * (pg_isready, psql, mysqladmin, redis-cli). Pendant un build, seul Internet est joignable (les
 * téléchargements sont simulés) : les services de compose n'existent pas encore.
 */
final class NetworkCommands implements Command
{
    public function names(): array
    {
        return ['curl', 'wget', 'ping', 'nc', 'netcat', 'mailpit', 'getent', 'nslookup', 'host', 'pg_isready', 'psql', 'mysqladmin', 'mariadb-admin', 'mysql', 'mariadb', 'healthcheck.sh', 'redis-cli', 'git', 'ssh', 'scp'];
    }

    public function run(string $name, array $args, Machine $m, string $stdin, Interpreter $sh): Result
    {
        return match ($name) {
            'curl' => $this->curl($args, $m),
            'wget' => $this->wget($args, $m),
            'ping' => $this->ping($args, $m),
            'nc', 'netcat' => $this->nc($args, $m),
            'getent', 'nslookup', 'host' => $this->lookup($name, $args, $m),
            'mailpit' => \in_array($args[0] ?? '', ['readyz', 'livez'], true)
                ? (($m->network?->connect('localhost', 8025)['status'] ?? null) === 'open' ? Result::ok() : Result::error(1, "Error: connection refused\n"))
                : Result::ok("mailpit v1.21.8 compiled with go1.24.5 on linux/amd64\n"),
            'pg_isready' => $this->pgIsReady($args, $m),
            'psql' => $this->psql($args, $m),
            'mysqladmin', 'mariadb-admin', 'healthcheck.sh' => $this->mysqlAdmin($name, $args, $m),
            'mysql', 'mariadb' => $this->mysql($args, $m),
            'redis-cli' => $this->redisCli($args, $m),
            'git' => $this->git($args, $m),
            'ssh', 'scp' => Result::error(255, "ssh: connect to host: Network is unreachable\n"),
            default => Result::ok(),
        };
    }

    /** @return array{0: string, 1: int, 2: string, 3: string} schéma, port, hôte, chemin */
    private function parseUrl(string $url, int $defaultPort = 80): array
    {
        if (!preg_match('#^[a-z]+://#i', $url)) {
            $url = 'http://'.$url;
        }
        $parts = parse_url($url) ?: [];
        $scheme = strtolower($parts['scheme'] ?? 'http');

        return [$scheme, (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : $defaultPort)), (string) ($parts['host'] ?? 'localhost'), ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '')];
    }

    private function isInternet(string $host): bool
    {
        return str_contains($host, '.') && !preg_match('/^\d+\.\d+\.\d+\.\d+$/', $host) && !str_ends_with($host, '.local') && $host !== 'host.docker.internal';
    }

    private function curl(array $args, Machine $m): Result
    {
        $url = null;
        $output = null;
        $fail = false;
        $silent = false;
        $showError = false;
        $head = false;
        $include = false;
        $method = 'GET';
        $headers = [];
        $data = '';
        $writeOut = null;
        for ($i = 0; $i < \count($args); ++$i) {
            $arg = $args[$i];
            if (\in_array($arg, ['-o', '--output'], true)) {
                $output = $args[++$i] ?? null;
            } elseif ($arg === '-O' || $arg === '--remote-name') {
                $output = '__remote__';
            } elseif (\in_array($arg, ['-X', '--request'], true)) {
                $method = strtoupper($args[++$i] ?? 'GET');
            } elseif (\in_array($arg, ['-H', '--header'], true)) {
                [$key, $value] = array_pad(explode(':', $args[++$i] ?? '', 2), 2, '');
                $headers[trim($key)] = trim($value);
            } elseif (\in_array($arg, ['-d', '--data', '--data-raw'], true)) {
                $data = $args[++$i] ?? '';
                $method = $method === 'GET' ? 'POST' : $method;
            } elseif (\in_array($arg, ['-w', '--write-out'], true)) {
                $writeOut = $args[++$i] ?? '';
            } elseif (\in_array($arg, ['-A', '--user-agent'], true)) {
                $headers['user-agent'] = $args[++$i] ?? '';
            } elseif (\in_array($arg, ['-u', '--user', '-e', '--connect-timeout', '-m', '--max-time', '--retry'], true)) {
                ++$i;
            } elseif (str_starts_with($arg, '--') ) {
                $fail = $fail || \in_array($arg, ['--fail', '--fail-with-body'], true);
                $silent = $silent || $arg === '--silent';
                $showError = $showError || $arg === '--show-error';
                $head = $head || $arg === '--head';
                $include = $include || $arg === '--include';
            } elseif (str_starts_with($arg, '-') && \strlen($arg) > 1) {
                $flags = substr($arg, 1);
                $fail = $fail || str_contains($flags, 'f');
                $silent = $silent || str_contains($flags, 's');
                $showError = $showError || str_contains($flags, 'S');
                $head = $head || str_contains($flags, 'I');
                $include = $include || str_contains($flags, 'i');
                if (str_ends_with($flags, 'o')) {
                    $output = $args[++$i] ?? null;
                }
                if (str_contains($flags, 'O')) {
                    $output = '__remote__';
                }
            } else {
                $url = $arg;
            }
        }
        if ($url === null) {
            return Result::error(2, "curl: try 'curl --help' or 'curl --manual' for more information\n");
        }
        [$scheme, $port, $host, $path] = $this->parseUrl($url);
        $errors = !$silent || $showError;

        if ($this->isInternet($host)) {
            // Téléchargement depuis Internet : le contenu est simulé.
            $content = str_contains($host, 'getcomposer.org') ? "<?php // installateur de Composer (getcomposer.org)\n" : sprintf("# contenu téléchargé depuis %s (simulé)\n", $url);
            if ($output !== null) {
                $target = $output === '__remote__' ? basename(parse_url($url, PHP_URL_PATH) ?: 'index.html') : $output;
                $m->fs->write($m->path($target), $content, null, $m->isRoot() ? null : $m->userName());
                $content = '';
            }

            return Result::ok($content, 1.2);
        }
        if ($m->network === null) {
            return Result::error(6, $errors ? "curl: (6) Could not resolve host: {$host}\n" : '', '', 0.1);
        }
        $headers['user-agent'] ??= 'curl/8.14.1';
        $response = $m->network->http($method, sprintf('%s://%s:%d%s', $scheme, $host, $port, $path), $headers, $data);
        if (isset($response['error'])) {
            [$code, $message] = match ($response['error']) {
                'unresolved' => [6, "curl: (6) Could not resolve host: {$host}"],
                'refused' => [7, sprintf("curl: (7) Failed to connect to %s port %d after 0 ms: Couldn't connect to server", $host, $port)],
                'reset' => [56, 'curl: (56) Recv failure: Connection reset by peer'],
                default => [52, 'curl: (52) Empty reply from server'],
            };

            return Result::error($code, $errors ? $message."\n" : '', '', 0.1);
        }
        $body = $head ? '' : $response['body'];
        $prefix = '';
        if ($head || $include) {
            $prefix = sprintf("HTTP/1.1 %d %s\r\n", $response['status'], self::reason($response['status']));
            foreach ($response['headers'] as $key => $value) {
                $prefix .= $key.': '.$value."\r\n";
            }
            $prefix .= "\r\n";
        }
        if ($fail && $response['status'] >= 400) {
            return Result::error(22, $errors ? sprintf("curl: (22) The requested URL returned error: %d\n", $response['status']) : '', '', 0.1);
        }
        $stdout = $prefix.$body;
        if ($output !== null) {
            $m->fs->write($m->path($output === '__remote__' ? basename($path) ?: 'index.html' : $output), $body);
            $stdout = $prefix;
        }
        if ($writeOut !== null) {
            $stdout .= str_replace(['%{http_code}', '\n'], [(string) $response['status'], "\n"], $writeOut);
        }

        return Result::ok($stdout, 0.1);
    }

    private function wget(array $args, Machine $m): Result
    {
        $output = null;
        $quiet = false;
        $spider = false;
        $url = null;
        for ($i = 0; $i < \count($args); ++$i) {
            $arg = $args[$i];
            if ($arg === '-O') {
                $output = $args[++$i] ?? null;
            } elseif (str_starts_with($arg, '-O') && \strlen($arg) > 2) {
                $output = substr($arg, 2);
            } elseif ($arg === '--spider') {
                $spider = true;
            } elseif (str_starts_with($arg, '-')) {
                $quiet = $quiet || (str_contains($arg, 'q') && !str_starts_with($arg, '--')) || $arg === '--quiet';
                if (!str_starts_with($arg, '--') && ($position = strpos($arg, 'O')) !== false) {
                    $rest = substr($arg, $position + 1);
                    $output = $rest !== '' ? $rest : ($args[++$i] ?? null);
                }
            } else {
                $url = $arg;
            }
        }
        if ($url === null) {
            return Result::error(1, "BusyBox v1.37.0 multi-call binary.\n\nUsage: wget [-cqS] [--spider] [-O FILE] URL...\n");
        }
        [$scheme, $port, $host, $path] = $this->parseUrl($url);
        if ($this->isInternet($host)) {
            $m->fs->write($m->path($output ?? basename($path) ?: 'index.html'), sprintf("# contenu téléchargé depuis %s (simulé)\n", $url));

            return Result::ok($quiet ? '' : "Connecting to {$host}\nsaving to '".($output ?? basename($path))."'\n", 1.0);
        }
        // Le wget de BusyBox (Alpine) se présente sobrement ; celui de GNU (Debian) donne sa version.
        $agent = $m->facts->os === 'alpine' ? 'Wget' : 'Wget/1.25.0';
        $response = $m->network?->http('GET', sprintf('%s://%s:%d%s', $scheme, $host, $port, $path), ['user-agent' => $agent]) ?? ['error' => 'unresolved', 'message' => ''];
        if (isset($response['error'])) {
            $message = match ($response['error']) {
                'unresolved' => "wget: bad address '{$host}'",
                'refused' => "wget: can't connect to remote host ({$host}): Connection refused",
                default => 'wget: error getting response: Connection reset by peer',
            };

            return Result::error(1, ($quiet ? '' : "Connecting to {$host}:{$port}\n").$message."\n");
        }
        if ($response['status'] >= 400) {
            return Result::error(1, ($quiet ? '' : "Connecting to {$host}:{$port}\n").sprintf("wget: server returned error: HTTP/1.1 %d %s\n", $response['status'], self::reason($response['status'])));
        }
        if ($spider) {
            return Result::ok($quiet ? '' : "Connecting to {$host}:{$port}\nremote file exists\n");
        }
        if ($output === '-') {
            return Result::ok($response['body']);
        }
        $m->fs->write($m->path($output ?? (basename($path) ?: 'index.html')), $response['body']);

        return Result::ok($quiet ? '' : "Connecting to {$host}:{$port}\nsaving to 'index.html'\n");
    }

    private function ping(array $args, Machine $m): Result
    {
        $host = end($args) ?: '';
        $ip = $this->isInternet($host) ? '142.250.179.110' : ($host === 'localhost' ? '127.0.0.1' : $m->network?->resolve($host));
        if ($ip === null) {
            return Result::error(1, "ping: bad address '{$host}'\n");
        }

        return Result::ok(sprintf("PING %s (%s): 56 data bytes\n64 bytes from %s: seq=0 ttl=64 time=0.087 ms\n64 bytes from %s: seq=1 ttl=64 time=0.112 ms\n\n--- %s ping statistics ---\n2 packets transmitted, 2 packets received, 0%% packet loss\nround-trip min/avg/max = 0.087/0.099/0.112 ms\n", $host, $ip, $ip, $ip, $host), 1.0);
    }

    private function nc(array $args, Machine $m): Result
    {
        $operands = array_values(array_filter($args, static fn ($a) => $a[0] !== '-' && !is_numeric($a) || preg_match('/^\d+$/', $a)));
        $operands = array_values(array_filter($args, static fn ($a) => $a[0] !== '-'));
        if (\count($operands) < 2) {
            return Result::error(1, "BusyBox v1.37.0 multi-call binary.\n\nUsage: nc [OPTIONS] HOST PORT  - connect\n");
        }
        $verbose = (bool) array_filter($args, static fn ($a) => $a[0] === '-' && str_contains($a, 'v'));
        [$host, $port] = [$operands[0], (int) $operands[1]];
        $connection = $m->network?->connect($host, $port) ?? ['status' => 'unresolved', 'process' => null, 'container' => null];

        return match ($connection['status']) {
            'open' => Result::ok($verbose ? "{$host} ({$m->network?->resolve($host)}:{$port}) open\n" : ''),
            'refused' => Result::error(1, $verbose ? "nc: {$host} ({$m->network?->resolve($host)}:{$port}): Connection refused\n" : ''),
            default => Result::error(1, "nc: bad address '{$host}'\n"),
        };
    }

    private function lookup(string $name, array $args, Machine $m): Result
    {
        if ($name === 'getent' && \in_array($args[0] ?? '', ['passwd', 'group'], true)) {
            $lines = array_values(array_filter(explode("\n", (string) $m->fs->read('/etc/'.$args[0]))));
            $keys = \array_slice($args, 1);
            if ($keys === []) {
                return Result::ok($lines === [] ? '' : implode("\n", $lines)."\n");
            }
            $found = array_values(array_filter($lines, static function (string $line) use ($keys): bool {
                $fields = explode(':', $line);

                return \in_array($fields[0], $keys, true) || \in_array($fields[2] ?? '', $keys, true);
            }));

            return $found === [] ? new Result(2) : Result::ok(implode("\n", $found)."\n");
        }
        $host = end($args) ?: '';
        $ip = $host === 'localhost' ? '127.0.0.1' : ($this->isInternet($host) ? '142.250.179.110' : $m->network?->resolve($host));
        if ($ip === null) {
            return match ($name) {
                'getent' => new Result(2),
                'host' => Result::error(1, "Host {$host} not found: 3(NXDOMAIN)\n"),
                default => Result::error(1, "Server:\t\t127.0.0.11\nAddress:\t127.0.0.11:53\n\n** server can't find {$host}: NXDOMAIN\n"),
            };
        }

        return Result::ok(match ($name) {
            'getent' => sprintf("%-15s %s\n", $ip, $host),
            'host' => "{$host} has address {$ip}\n",
            default => "Server:\t\t127.0.0.11\nAddress:\t127.0.0.11:53\n\nNon-authoritative answer:\nName:\t{$host}\nAddress: {$ip}\n",
        });
    }

    /** @return array<string,string> options -h, -p, -U, -d… */
    private function clientOptions(array $args): array
    {
        $options = [];
        for ($i = 0; $i < \count($args); ++$i) {
            if (preg_match('/^--(host|port|username|user|dbname|password)=(.*)$/', $args[$i], $long)) {
                $options[['host' => 'h', 'port' => 'p', 'username' => 'U', 'user' => 'u', 'dbname' => 'd', 'password' => 'P'][$long[1]]] = $long[2];
            } elseif (preg_match('/^-([hpUudcP])(.*)$/', $args[$i], $short)) {
                $options[$short[1]] = $short[2] !== '' ? $short[2] : ($args[++$i] ?? '');
            } elseif ($args[$i][0] !== '-') {
                $options['_'][] = $args[$i];
            }
        }

        return $options;
    }

    /** @return array{0: ?string, 1: ?\Forelse\DockerSim\State\Container, 2: string} erreur, conteneur, hôte */
    private function reachDatabase(Machine $m, string $host, int $port, array $processes): array
    {
        if ($host === '' || $host === 'localhost' || $host === '127.0.0.1') {
            $host = 'localhost';
        }
        $connection = $m->network?->connect($host, $port) ?? ['status' => 'unresolved', 'process' => null, 'container' => null];
        if ($connection['status'] === 'unresolved') {
            return ['unresolved', null, $host];
        }
        if ($connection['status'] !== 'open' || !\in_array($connection['process'], $processes, true)) {
            return ['refused', $connection['container'], $host];
        }

        return [null, $connection['container'], $host];
    }

    private function pgIsReady(array $args, Machine $m): Result
    {
        $o = $this->clientOptions($args);
        $port = (int) ($o['p'] ?? 5432);
        [$error, , $host] = $this->reachDatabase($m, $o['h'] ?? ($m->env['PGHOST'] ?? ''), $port, ['postgres']);
        $socket = $host === 'localhost' && !isset($o['h']) ? '/var/run/postgresql' : $host;

        return match ($error) {
            null => Result::ok(sprintf("%s:%d - accepting connections\n", $socket, $port)),
            'unresolved' => Result::error(2, sprintf("%s:%d - no attempt\n", $host, $port)),
            default => Result::error(2, sprintf("%s:%d - no response\n", $socket, $port)),
        };
    }

    private function psql(array $args, Machine $m): Result
    {
        $o = $this->clientOptions($args);
        $port = (int) ($o['p'] ?? 5432);
        [$error, $container, $host] = $this->reachDatabase($m, $o['h'] ?? ($m->env['PGHOST'] ?? ''), $port, ['postgres']);
        if ($error === 'unresolved') {
            return Result::error(2, sprintf("psql: error: could not translate host name \"%s\" to address: Name does not resolve\n", $host));
        }
        if ($error !== null) {
            return Result::error(2, $host === 'localhost'
                ? "psql: error: connection to server on socket \"/var/run/postgresql/.s.PGSQL.5432\" failed: No such file or directory\n\tIs the server running locally and accepting connections on that socket?\n"
                : sprintf("psql: error: connection to server at \"%s\" (%s), port %d failed: Connection refused\n\tIs the server running on that host and accepting TCP/IP connections?\n", $host, $m->network?->resolve($host), $port));
        }
        $env = $container?->env ?? [];
        // Une base déjà initialisée garde les identifiants de sa création (volume réutilisé).
        if (isset($container?->processOptions['postgres'])) {
            $saved = $container->processOptions['postgres'];
            $env = ['POSTGRES_USER' => $saved['user'], 'POSTGRES_DB' => $saved['database'], 'POSTGRES_PASSWORD' => $saved['password']] + $env;
        }
        $superuser = $env['POSTGRES_USER'] ?? 'postgres';
        $user = $o['U'] ?? ($m->env['PGUSER'] ?? $m->userName());
        $database = $o['d'] ?? ($o['_'][0] ?? $user);
        if ($user !== $superuser) {
            return Result::error(2, sprintf("psql: error: connection to server %s failed: FATAL:  role \"%s\" does not exist\n", $host === 'localhost' ? 'on socket "/var/run/postgresql/.s.PGSQL.5432"' : sprintf('at "%s", port %d', $host, $port), $user));
        }
        $databases = array_unique(['postgres', $env['POSTGRES_DB'] ?? $superuser, $superuser]);
        if (!\in_array($database, $databases, true)) {
            return Result::error(2, sprintf("psql: error: connection to server failed: FATAL:  database \"%s\" does not exist\n", $database));
        }
        if ($host !== 'localhost' && ($env['POSTGRES_HOST_AUTH_METHOD'] ?? '') !== 'trust' && ($m->env['PGPASSWORD'] ?? null) !== ($env['POSTGRES_PASSWORD'] ?? '')) {
            return Result::error(2, sprintf("psql: error: connection to server at \"%s\" (%s), port %d failed: FATAL:  password authentication failed for user \"%s\"\n", $host, $m->network?->resolve($host), $port, $user));
        }
        $sql = $o['c'] ?? null;
        if ($sql === null) {
            return Result::ok("psql (18.0 (Debian 18.0-1.pgdg13+3))\nType \"help\" for help.\n\n(le simulateur n'ouvre pas de session interactive : utilisez -c \"...\")\n");
        }
        if (preg_match('/^\s*\\\\l/', $sql)) {
            $out = "                                 List of databases\n   Name    |  Owner   | Encoding | Collate    |  Ctype\n-----------+----------+----------+------------+------------\n";
            foreach ($databases as $name) {
                $out .= sprintf(" %-9s | %-8s | UTF8     | en_US.utf8 | en_US.utf8\n", $name, $superuser);
            }

            return Result::ok($out.sprintf("(%d rows)\n\n", \count($databases)));
        }
        if (preg_match('/^\s*select\s+1\s*;?\s*$/i', $sql)) {
            return Result::ok(" ?column? \n----------\n        1\n(1 row)\n\n");
        }
        if (preg_match('/^\s*select\s+version\(\)/i', $sql)) {
            return Result::ok("                         version\n----------------------------------------------------------\n PostgreSQL 18.0 (Debian 18.0-1.pgdg13+3) on x86_64-pc-linux-gnu\n(1 row)\n\n");
        }

        return Result::ok("(le simulateur ne rejoue pas les requêtes SQL : seule la connexion est vérifiée)\n");
    }

    private function mysqlAdmin(string $name, array $args, Machine $m): Result
    {
        $o = $this->clientOptions($args);
        $host = $o['h'] ?? 'localhost';
        [$error, $container] = $this->reachDatabase($m, $host, (int) ($o['P'] ?? 3306), ['mysql', 'mariadb']);
        if ($name === 'healthcheck.sh') {
            return $error === null ? Result::ok() : new Result(1);
        }
        if ($error !== null) {
            return Result::error(1, $error === 'unresolved'
                ? "mysqladmin: connect to server at '{$host}' failed\nerror: 'Unknown MySQL server host '{$host}' (-2)'\n"
                : "mysqladmin: connect to server at '{$host}' failed\nerror: 'Can't connect to local server through socket '/var/run/mysqld/mysqld.sock' (2)'\nCheck that mysqld is running and that the socket: '/var/run/mysqld/mysqld.sock' exists!\n");
        }

        return Result::ok("mysqld is alive\n");
    }

    private function mysql(array $args, Machine $m): Result
    {
        $o = $this->clientOptions($args);
        $host = $o['h'] ?? 'localhost';
        [$error] = $this->reachDatabase($m, $host, (int) ($o['P'] ?? 3306), ['mysql', 'mariadb']);
        if ($error !== null) {
            return Result::error(1, $error === 'unresolved' ? "ERROR 2005 (HY000): Unknown MySQL server host '{$host}' (-2)\n" : "ERROR 2002 (HY000): Can't connect to local MySQL server through socket '/var/run/mysqld/mysqld.sock' (2)\n");
        }

        return Result::ok("(le simulateur ne rejoue pas les requêtes SQL : seule la connexion est vérifiée)\n");
    }

    private function redisCli(array $args, Machine $m): Result
    {
        $o = $this->clientOptions($args);
        $host = $o['h'] ?? '127.0.0.1';
        $port = (int) ($o['p'] ?? 6379);
        [$error] = $this->reachDatabase($m, $host, $port, ['redis']);
        if ($error !== null) {
            return Result::error(1, $error === 'unresolved' ? "Could not connect to Redis at {$host}:{$port}: Name does not resolve\n" : "Could not connect to Redis at {$host}:{$port}: Connection refused\n");
        }
        $command = strtoupper($o['_'][0] ?? '');

        return Result::ok(match ($command) { 'PING' => "PONG\n", '' => "{$host}:{$port}> (session interactive non simulée)\n", default => "OK\n" });
    }

    private function git(array $args, Machine $m): Result
    {
        $sub = $args[0] ?? '';
        if ($sub === '--version' || $sub === 'version') {
            return Result::ok("git version 2.49.1\n");
        }
        if ($sub === 'config') {
            return Result::ok();
        }
        if ($sub === 'clone') {
            $m->note('git clone : le simulateur ne télécharge pas de dépôt ; copiez le code depuis le contexte de build (COPY).');

            return Result::error(128, sprintf("Cloning into '%s'...\nfatal: unable to access '%s': Could not resolve host\n", basename((string) end($args), '.git'), end($args)));
        }

        return Result::error(128, "fatal: not a git repository (or any of the parent directories): .git\n");
    }

    public static function reason(int $status): string
    {
        return match ($status) {
            200 => 'OK', 201 => 'Created', 204 => 'No Content', 301 => 'Moved Permanently', 302 => 'Found', 304 => 'Not Modified',
            400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed',
            500 => 'Internal Server Error', 502 => 'Bad Gateway', 503 => 'Service Unavailable', 504 => 'Gateway Timeout',
            default => 'Unknown',
        };
    }
}
