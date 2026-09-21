<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Runtime;

use Forelse\DockerSim\Fs\Path;
use Forelse\DockerSim\Http\HttpRequest;
use Forelse\DockerSim\Http\HttpResponse;
use Forelse\DockerSim\State\Container;

/**
 * Exécute pour de vrai le PHP d'un conteneur (requête HTTP ou script en ligne de commande),
 * dans sa racine sur disque, avec son environnement.
 *
 * Deux modes : dans le navigateur (php-wasm, pas de processus), le script est inclus dans la requête
 * en cours ; ailleurs (tests natifs), il tourne dans un sous-processus PHP, pour qu'un exit() ou une
 * fonction déclarée deux fois ne fasse pas tomber l'appelant.
 */
final class PhpExecutor
{
    public function __construct(private readonly Materializer $materializer)
    {
    }

    public static function inProcess(): bool
    {
        $forced = getenv('DOCKER_SIM_INPROCESS');
        if ($forced !== false && $forced !== '') {
            return $forced === '1';
        }

        return \PHP_INT_SIZE === 4 || !\function_exists('proc_open') || str_contains(php_uname('m'), 'wasm');
    }

    /**
     * @param array<string,string> $serverExtra
     */
    public function serve(Container $container, string $script, string $documentRoot, HttpRequest $request, string $scriptName, array $serverExtra = []): HttpResponse
    {
        $real = $this->materializer->real($container, $script);
        $env = $this->environment($container);
        $server = [
            'REQUEST_METHOD' => $request->method,
            'REQUEST_URI' => $request->requestUri(),
            'QUERY_STRING' => $request->query,
            'SCRIPT_NAME' => $scriptName,
            'PHP_SELF' => $scriptName,
            'SCRIPT_FILENAME' => $script,
            'DOCUMENT_ROOT' => $documentRoot,
            'SERVER_NAME' => $request->host,
            'SERVER_PORT' => (string) $request->port,
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'SERVER_SOFTWARE' => $serverExtra['SERVER_SOFTWARE'] ?? 'Apache/2.4.65 (Debian)',
            'GATEWAY_INTERFACE' => 'CGI/1.1',
            'REMOTE_ADDR' => $request->clientIp,
            'REQUEST_TIME' => (string) time(),
            'REQUEST_TIME_FLOAT' => (string) microtime(true),
            'HTTP_HOST' => $request->headers['host'] ?? $request->host.($request->port !== 80 ? ':'.$request->port : ''),
        ];
        // Ce que le serveur web impose (SCRIPT_FILENAME de nginx, par exemple) l'emporte.
        $server = $serverExtra + $server;
        foreach ($request->headers as $name => $value) {
            $key = 'HTTP_'.strtoupper(str_replace('-', '_', $name));
            $server[$key] = $value;
            if ($name === 'content-type' || $name === 'content-length') {
                $server[strtoupper(str_replace('-', '_', $name))] = $value;
            }
        }
        $payload = ['mode' => 'http', 'script' => $real, 'server' => $server + $env, 'env' => $env, 'body' => $request->body, 'query' => $request->query, 'headers' => $request->headers, 'display' => $this->displayErrors($container)];
        $result = self::inProcess() ? self::execute($payload) : $this->subprocess($payload);
        $body = $this->translatePaths($container, $result['output']);
        $response = new HttpResponse($result['status'] ?: 200, $result['headers'], $body, null, $result['errors'], $container->name, $script);
        if ($result['fatal']) {
            $response->status = 500;
        }

        return $response;
    }

    /**
     * @param list<string>          $argv script (chemin du conteneur) et arguments
     * @param array<string,string>  $env
     *
     * @return array{0: int, 1: string}
     */
    public function cli(Container $container, array $argv, string $cwd, array $env, string $stdin = ''): array
    {
        $real = $this->materializer->real($container, $argv[0]);
        $environment = $this->environment($container, $env);
        $payload = ['mode' => 'cli', 'script' => $real, 'argv' => [$argv[0], ...\array_slice($argv, 1)], 'cwd' => $this->materializer->real($container, $cwd), 'server' => $environment, 'env' => $environment, 'stdin' => $stdin, 'display' => true];
        $result = self::inProcess() ? self::execute($payload) : $this->subprocess($payload);

        return [$result['exit'], $this->translatePaths($container, $result['output'])];
    }

    /**
     * L'exécution elle-même (dans ce processus ou dans le sous-processus).
     *
     * @param array<string,mixed> $payload
     *
     * @return array{status: int, headers: array<string,string>, output: string, errors: list<string>, fatal: bool, exit: int}
     */
    /**
     * La pile d'appels telle que PHP l'afficherait dans le conteneur : les appels du simulateur qui
     * incluent le script (PhpExecutor, bin/php-exec) disparaissent, la pile s'arrête sur {main}.
     */
    private static function trace(\Throwable $e): string
    {
        $lines = [];
        foreach ($e->getTrace() as $frame) {
            $file = (string) ($frame['file'] ?? '');
            if ($file === __FILE__ || str_ends_with($file, '/bin/php-exec') || str_contains((string) ($frame['function'] ?? ''), '{closure')) {
                break;
            }
            $call = isset($frame['class']) ? $frame['class'].($frame['type'] ?? '->').$frame['function'] : ($frame['function'] ?? '');
            $lines[] = sprintf('#%d %s: %s()', \count($lines), $file !== '' ? $file.'('.($frame['line'] ?? 0).')' : '[internal function]', $call);
        }
        $lines[] = sprintf('#%d {main}', \count($lines));

        return implode("\n", $lines);
    }

    public static function execute(array $payload): array
    {
        if (!class_exists(PhpExit::class)) {
            require_once __DIR__.'/PhpExit.php';
        }
        $saved = [$_SERVER, $_GET, $_POST, $_COOKIE, $_FILES, $_REQUEST, $_ENV, getcwd()];
        $previousEnv = [];
        foreach ($payload['env'] as $key => $value) {
            $previousEnv[$key] = getenv($key);
            putenv($key.'='.$value);
        }
        $_ENV = $payload['env'] + $_ENV;
        $errors = [];
        $fatal = false;
        $exit = 0;
        $status = 200;
        $display = (bool) $payload['display'];
        if ($payload['mode'] === 'http') {
            $_SERVER = $payload['server'] + ['argv' => [], 'argc' => 0];
            parse_str((string) $payload['query'], $_GET);
            $_POST = [];
            $contentType = strtolower((string) ($payload['headers']['content-type'] ?? ''));
            if (\in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'PATCH', 'DELETE'], true) && str_starts_with($contentType, 'application/x-www-form-urlencoded')) {
                parse_str((string) $payload['body'], $_POST);
            }
            $_COOKIE = [];
            foreach (explode(';', (string) ($payload['headers']['cookie'] ?? '')) as $pair) {
                if (str_contains($pair, '=')) {
                    [$name, $value] = explode('=', trim($pair), 2);
                    $_COOKIE[$name] = urldecode($value);
                }
            }
            $_REQUEST = $_GET + $_POST;
            if (\function_exists('header_remove') && !headers_sent()) {
                header_remove();
            }
            if (!headers_sent()) {
                http_response_code(200);
            }
            @chdir(\dirname((string) $payload['script']));
        } else {
            $_SERVER = $payload['server'] + ['argv' => $payload['argv'], 'argc' => \count($payload['argv']), 'SCRIPT_NAME' => $payload['argv'][0], 'SCRIPT_FILENAME' => $payload['argv'][0], 'PHP_SELF' => $payload['argv'][0]];
            $GLOBALS['argv'] = $payload['argv'];
            $GLOBALS['argc'] = \count($payload['argv']);
            @chdir((string) $payload['cwd']);
        }

        set_error_handler(static function (int $severity, string $message, string $file, int $line) use (&$errors, $display): bool {
            if (!(error_reporting() & $severity)) {
                return true;
            }
            $label = match ($severity) { \E_WARNING, \E_USER_WARNING => 'Warning', \E_NOTICE, \E_USER_NOTICE => 'Notice', \E_DEPRECATED, \E_USER_DEPRECATED => 'Deprecated', default => 'Warning' };
            $errors[] = sprintf('PHP %s:  %s in %s on line %d', $label, $message, $file, $line);
            if ($display) {
                echo sprintf("\n<br />\n<b>%s</b>:  %s in <b>%s</b> on line <b>%d</b><br />\n", $label, $message, $file, $line);
            }

            return true;
        });
        $level = ob_get_level();
        ob_start();
        try {
            (static function (string $__script): void {
                include $__script;
            })((string) $payload['script']);
        } catch (PhpExit $e) {
            $exit = $e->status;
        } catch (\Throwable $e) {
            $fatal = true;
            $exit = 255;
            $message = sprintf("PHP Fatal error:  Uncaught %s: %s in %s:%d\nStack trace:\n%s\n  thrown in %s on line %d", $e::class, $e->getMessage(), $e->getFile(), $e->getLine(), self::trace($e), $e->getFile(), $e->getLine());
            $errors[] = $message;
            if ($display) {
                echo sprintf("<br />\n<b>Fatal error</b>:  Uncaught %s: %s in %s:%d\nStack trace:\n%s\n  thrown in <b>%s</b> on line <b>%d</b><br />\n", $e::class, htmlspecialchars($e->getMessage()), $e->getFile(), $e->getLine(), self::trace($e), $e->getFile(), $e->getLine());
            }
        } finally {
            restore_error_handler();
        }
        $output = '';
        while (ob_get_level() > $level) {
            $output = ob_get_clean().$output;
        }
        $headers = [];
        if ($payload['mode'] === 'http') {
            $status = (int) (http_response_code() ?: 200);
            foreach (headers_list() as $header) {
                [$name, $value] = array_pad(explode(':', $header, 2), 2, '');
                $headers[trim($name)] = trim($value);
            }
        }
        [$_SERVER, $_GET, $_POST, $_COOKIE, $_FILES, $_REQUEST, $_ENV, $cwd] = $saved;
        if ($cwd !== false) {
            @chdir($cwd);
        }
        foreach ($previousEnv as $key => $value) {
            putenv($value === false ? $key : $key.'='.$value);
        }

        return ['status' => $status, 'headers' => $headers, 'output' => $output, 'errors' => $errors, 'fatal' => $fatal, 'exit' => $exit];
    }

    /**
     * @param array<string,mixed> $payload
     *
     * @return array{status: int, headers: array<string,string>, output: string, errors: list<string>, fatal: bool, exit: int}
     */
    private function subprocess(array $payload): array
    {
        $runner = \dirname(__DIR__, 2).'/bin/php-exec';
        $process = proc_open([\PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'display_errors=stderr', $runner], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!\is_resource($process)) {
            return self::execute($payload);
        }
        fwrite($pipes[0], json_encode($payload, \JSON_INVALID_UTF8_SUBSTITUTE));
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        $separator = "\n@@PHP-EXEC@@";
        $marker = strrpos($stdout, $separator);
        if ($marker === false) {
            // Le script a appelé exit() (ou PHP s'est arrêté) : sa sortie est brute, le code est celui du processus.
            $output = $stdout;
            $fatal = str_contains($stderr, 'Fatal error');

            return ['status' => $fatal ? 500 : 200, 'headers' => [], 'output' => $output.($payload['mode'] === 'cli' ? $stderr : ''), 'errors' => $stderr !== '' ? [trim($stderr)] : [], 'fatal' => $fatal, 'exit' => $code];
        }
        $result = json_decode(substr($stdout, $marker + \strlen($separator)), true) ?: [];
        $result['output'] = substr($stdout, 0, $marker);

        return $result + ['status' => 200, 'headers' => [], 'errors' => [], 'fatal' => false, 'exit' => 0];
    }

    /** @return array<string,string> */
    private function environment(Container $container, array $extra = []): array
    {
        $env = [];
        $fs = $this->materializer->fs($container);
        foreach ($extra + $container->env as $key => $value) {
            $env[$key] = $this->translateValue($fs, (string) $value);
        }
        $env['HOSTNAME'] = $container->hostname;

        return $env;
    }

    /** Un chemin absolu du conteneur dans une variable (/data/criee.sqlite, sqlite:////data/x) devient le chemin réel. */
    private function translateValue(\Forelse\DockerSim\Fs\DiskFs $fs, string $value): string
    {
        if (preg_match('#^(sqlite:///?)(/.*)$#', $value, $m)) {
            return $m[1].$this->translateValue($fs, $m[2]);
        }
        if (str_starts_with($value, '/') && $value !== '/' && !str_contains($value, ' ') && !str_contains($value, ':')) {
            $path = Path::normalize($value);
            $parent = \dirname($path);
            if ($fs->exists($path) || ($parent !== '/' && $fs->isDir($parent))) {
                return $fs->real($path);
            }
        }

        return $value;
    }

    /** Dans les messages, les chemins réels redeviennent ceux du conteneur. */
    public function translatePaths(Container $container, string $text): string
    {
        $replacements = [];
        foreach ($container->mounts as $mount) {
            $source = realpath($this->materializer->source($mount)) ?: $this->materializer->source($mount);
            $replacements[rtrim($source, '/')] = rtrim($mount['target'], '/');
        }
        $root = $this->materializer->rootfs($container);
        $replacements[realpath($root) ?: $root] = '';
        $replacements[$root] = '';
        uksort($replacements, static fn ($a, $b) => \strlen($b) <=> \strlen($a));

        return strtr($text, $replacements);
    }

    private function displayErrors(Container $container): bool
    {
        $fs = $this->materializer->fs($container);
        $display = true;
        foreach (['/usr/local/etc/php/php.ini', ...array_map(static fn ($f) => $f, $fs->files('/usr/local/etc/php/conf.d'))] as $file) {
            $content = $fs->read($file);
            if ($content !== null && preg_match_all('/^\s*display_errors\s*=\s*"?(\w+)"?/mi', $content, $m)) {
                $value = strtolower((string) end($m[1]));
                $display = !\in_array($value, ['off', '0', 'false', 'no', 'stderr'], true);
            }
        }

        return $display;
    }
}
