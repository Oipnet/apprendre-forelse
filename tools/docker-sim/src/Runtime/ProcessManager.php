<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Runtime;

use Forelse\DockerSim\Engine\Docker;
use Forelse\DockerSim\Engine\DockerException;
use Forelse\DockerSim\Http\ApacheServer;
use Forelse\DockerSim\Http\Nginx\ConfigSnapshot;
use Forelse\DockerSim\Http\Nginx\NginxConfig;
use Forelse\DockerSim\Shell\Facts;
use Forelse\DockerSim\Shell\Machine;
use Forelse\DockerSim\State\Container;
use Forelse\DockerSim\State\Image;

/**
 * Ce qui se passe au démarrage d'un conteneur : le script d'entrée s'exécute, puis le processus
 * principal. Un serveur (Apache, nginx, php-fpm, PostgreSQL…) démarre, écrit ses journaux et écoute
 * sur ses ports — ou s'arrête sur l'erreur de configuration qu'il aurait vraiment rencontrée. Une
 * commande ponctuelle s'exécute et le conteneur s'arrête avec son code de sortie.
 */
final class ProcessManager
{
    public function __construct(private readonly Docker $docker)
    {
    }

    public function start(Container $container, Image $image): void
    {
        $container->logs = $container->logs === [] ? [] : $container->logs;
        $container->listening = [];
        $container->listenAddresses = [];
        $container->process = null;
        $container->health = null;
        $container->processOptions = [];
        $container->error = null;
        $argv = $container->command;
        if ($argv === []) {
            throw new DockerException('no command specified', 125);
        }
        $machine = $this->docker->machine($container);
        $user = explode(':', (string) ($container->user ?? 'root'))[0];
        if ($user !== '' && !ctype_digit($user) && !isset($machine->facts->users[$user])) {
            throw new DockerException(sprintf('unable to find user %s: no matching entries in passwd file', $user), 126);
        }

        for ($hop = 0; $hop < 8; ++$hop) {
            $first = $argv[0];
            $name = basename($first);

            // Scripts d'entrée des images officielles (sans fichier dans le simulateur).
            if ($name === 'docker-php-entrypoint' && !$machine->fs->isFile('/usr/local/bin/docker-php-entrypoint')) {
                $rest = \array_slice($argv, 1);
                $argv = $rest === [] ? ['php', '-a'] : (str_starts_with($rest[0], '-') ? ['php', ...$rest] : $rest);
                continue;
            }
            if (\in_array($name, ['docker-entrypoint.sh', 'docker-entrypoint', '/docker-entrypoint.sh', 'entrypoint.sh'], true) && !$machine->fs->isFile($first) && !$machine->fs->isFile('/usr/local/bin/'.$name)) {
                $rest = \array_slice($argv, 1);
                $default = match ($image->kind) { 'postgres' => 'postgres', 'mysql' => 'mysqld', 'mariadb' => 'mariadbd', 'redis' => 'redis-server', 'node' => 'node', 'composer' => 'composer', default => null };
                $argv = $rest === [] ? [$default ?? 'sh'] : (str_starts_with($rest[0], '-') && $default !== null ? [$default, ...$rest] : $rest);
                continue;
            }
            // Forme shell (CMD apache2-foreground => /bin/sh -c "apache2-foreground") : le shell lance la commande.
            if (\in_array($name, ['sh', 'bash', 'ash', 'dash'], true) && ($argv[1] ?? '') === '-c' && isset($argv[2]) && !$machine->fs->isFile($first)) {
                if ($name === 'bash' && !$machine->facts->hasBinary('bash')) {
                    throw $this->notFound($first);
                }
                $split = $this->splitShellCommand($argv[2], $machine, \array_slice($argv, 3));
                if ($split === null) {
                    break;
                }
                [$prefixCode, $prefixOutput, $last, $stopOnFailure] = $split + [3 => true];
                $this->appendLogs($container, $prefixOutput);
                if (($prefixCode !== 0 && $stopOnFailure) || $last === null) {
                    $this->exited($container, $prefixCode);

                    return;
                }
                $argv = $last;
                continue;
            }
            // Un script de l'image (ENTRYPOINT ["docker-entrypoint.sh"] copié par l'apprenant).
            $script = str_contains($first, '/') ? $first : $this->docker->findInPath($machine, $first);
            // Un binaire de l'image (fichier virtuel : vide sur disque, mais avec une taille) n'est pas un script.
            $isBinary = $script !== null && $machine->fs->isFile($script) && $machine->fs->read($script) === '' && $machine->fs->size($script) > 0;
            if ($script !== null && $machine->fs->isFile($script) && !$isBinary && !$this->isKnownDaemon($name)) {
                $mode = $machine->fs->mode($script);
                if (($mode & 0111) === 0) {
                    throw new DockerException(sprintf('failed to create task for container: failed to create shim task: OCI runtime create failed: runc create failed: unable to start container process: error during container init: exec: "%s": permission denied: unknown', $first), 126);
                }
                $content = (string) $machine->fs->read($script);
                if (str_contains(strtok($content, "\n") ?: '', "\r")) {
                    throw new DockerException(sprintf('failed to create task for container: failed to create shim task: OCI runtime create failed: runc create failed: unable to start container process: error during container init: exec: "%s": no such file or directory: unknown', $first), 127);
                }
                if (preg_match('/^#!.*\b(sh|bash|ash|dash)\b/', $content) || !str_starts_with($content, '#!')) {
                    $machine->output = '';
                    $machine->execTarget = null;
                    $result = $this->docker->shell->runScript($content, $machine, \array_slice($argv, 1));
                    $this->appendLogs($container, $result->stdout);
                    if ($machine->execTarget !== null) {
                        $argv = $machine->execTarget;
                        $machine->execTarget = null;
                        continue;
                    }
                    $this->exited($container, $result->code);

                    return;
                }
            }
            break;
        }
        $container->processOptions['argv'] = $argv;
        $this->run($container, $image, $machine, $argv);
        $this->docker->saveFacts($container, $machine->facts);
        if ($container->isRunning()) {
            $this->health($container);
        }
    }

    /**
     * « migrate && apache2-foreground » : tout sauf la dernière commande s'exécute, la dernière devient
     * le processus principal. Null si le script est trop complexe (il s'exécute alors en entier).
     *
     * @param list<string> $positional
     *
     * @return array{0: int, 1: string, 2: ?list<string>}|null
     */
    private function splitShellCommand(string $script, Machine $machine, array $positional): ?array
    {
        try {
            $ast = (new \Forelse\DockerSim\Shell\Parser())->parse($script);
        } catch (\Forelse\DockerSim\Shell\SyntaxError) {
            return null;
        }
        if ($ast === []) {
            return [0, '', null];
        }
        $lastAndOr = array_pop($ast);
        $parts = $lastAndOr['parts'];
        $lastPart = array_pop($parts);
        $command = $lastPart[1]['commands'][0] ?? null;
        // Une dernière commande avec redirection (echo … > fichier) n'est pas un processus principal :
        // le script entier est exécuté par le shell.
        if (\count($lastPart[1]['commands']) !== 1 || ($command['type'] ?? '') !== 'simple' || $lastPart[0] === '||' || ($command['redirects'] ?? []) !== []) {
            return null;
        }
        // « sh -c 'exit 4' » : exit, cd, export… appartiennent au shell, ils ne deviennent pas le processus principal.
        $head = $command['words'][0] ?? null;
        $headText = \is_array($head) ? implode('', array_map(static fn ($part) => \is_array($part) ? (string) ($part[1] ?? '') : (string) $part, $head)) : '';
        if (\in_array($headText, ['exit', 'return', 'cd', 'export', 'unset', 'set', 'shift', 'true', 'false', ':', 'test', '[', 'read', 'eval', '.', 'source', 'wait', 'trap', 'umask', 'alias'], true)) {
            return null;
        }
        $output = '';
        $code = 0;
        $savedPositional = $machine->positional;
        $machine->positional = $positional;
        $prefix = [...$ast];
        if ($parts !== []) {
            $prefix[] = ['type' => 'andor', 'parts' => $parts];
        }
        if ($prefix !== []) {
            $machine->output = '';
            $code = $this->docker->shell->run($this->render($prefix, $script, $lastPart), $machine);
            $output = $machine->output;
        }
        $words = [];
        foreach ($command['words'] as $word) {
            array_push($words, ...$this->docker->shell->expandWord($word, $machine));
        }
        foreach ($command['assign'] as [$key, $word]) {
            $machine->env[$key] = $this->docker->shell->expandText($word, $machine);
        }
        $machine->positional = $savedPositional;
        if (($words[0] ?? '') === 'exec') {
            array_shift($words);
        }
        // « a && b » : b n'est lancé que si a réussit ; « a; b » : b est lancé quoi qu'il arrive (sauf set -e).
        $stopOnFailure = ($parts !== [] && $lastPart[0] === '&&') || $machine->errexit;

        return [$code, $output, $words === [] ? null : $words, $stopOnFailure];
    }

    /** Le texte du script sans sa dernière commande (on la retrouve par sa position dans la chaîne). */
    private function render(array $prefix, string $script, array $lastPart): string
    {
        $separator = max(strrpos($script, '&&') ?: -1, strrpos($script, ';') ?: -1, strrpos($script, "\n") ?: -1);

        return $separator > 0 ? substr($script, 0, $separator) : $script;
    }

    private function isKnownDaemon(string $name): bool
    {
        return \in_array($name, ['apache2-foreground', 'nginx', 'php-fpm', 'php', 'postgres', 'mysqld', 'mariadbd', 'redis-server', 'frankenphp', 'caddy'], true);
    }

    /** @param list<string> $argv */
    private function run(Container $container, Image $image, Machine $machine, array $argv): void
    {
        $name = basename($argv[0]);
        $args = \array_slice($argv, 1);
        [, $phpWarnings] = $machine->facts->loadedExtensions();
        $now = gmdate('D M d H:i:s.u Y');

        switch (true) {
            case \in_array($name, ['apache2-foreground', 'httpd-foreground'], true) || ($name === 'apache2ctl' && \in_array('FOREGROUND', $args, true)):
                if (!$machine->facts->hasBinary('apache2')) {
                    throw $this->notFound($argv[0]);
                }
                $this->startApache($container, $machine, $phpWarnings);

                return;
            case $name === 'nginx':
                if (!$machine->facts->hasBinary('nginx')) {
                    throw $this->notFound($argv[0]);
                }
                $this->startNginx($container, $machine, $args);

                return;
            case $name === 'php-fpm' || preg_match('/^php-fpm\d/', $name) === 1:
                if (!$machine->facts->hasBinary('php-fpm')) {
                    throw $this->notFound($argv[0]);
                }
                $this->startFpm($container, $machine, $phpWarnings);

                return;
            case $name === 'php' && ($args[0] ?? '') === '-S':
                $this->startBuiltinServer($container, $machine, $args, $phpWarnings);

                return;
            case \in_array($name, ['frankenphp', 'caddy'], true):
                $this->listen($container, $name === 'frankenphp' ? 'frankenphp' : 'static', [80, 443, 2019]);
                $this->appendLogs($container, sprintf("{\"level\":\"info\",\"ts\":%d,\"msg\":\"using config from file\",\"file\":\"/etc/caddy/Caddyfile\"}\n{\"level\":\"info\",\"ts\":%d,\"msg\":\"serving initial configuration\"}\n", time(), time()));
                $container->processOptions['docroot'] = $image->docroot ?? '/app/public';

                return;
            case $name === 'postgres':
                $this->startPostgres($container, $machine, $args);

                return;
            case \in_array($name, ['mysqld', 'mariadbd'], true):
                $this->startMysql($container, $machine, $name);

                return;
            case $name === 'redis-server':
                $this->listen($container, 'redis', [6379]);
                $this->appendLogs($container, sprintf("1:C %s * oO0OoO0OoO0Oo Redis is starting oO0OoO0OoO0Oo\n1:C %s * Redis version=8.2.1, bits=64, commit=00000000, modified=0, pid=1, just started\n1:M %s * Server initialized\n1:M %s * Ready to accept connections tcp\n", gmdate('d M Y H:i:s.v'), gmdate('d M Y H:i:s.v'), gmdate('d M Y H:i:s.v'), gmdate('d M Y H:i:s.v')));

                return;
            case \in_array($name, ['mailpit', 'MailHog'], true):
                $this->listen($container, 'mail', [1025, 8025]);
                $container->processOptions['docroot'] = $image->docroot ?? '/mailpit-ui';
                $this->appendLogs($container, sprintf("time=\"%s\" level=info msg=\"[smtpd] starting on [::]:1025 (no encryption)\"\ntime=\"%s\" level=info msg=\"[http] starting on [::]:8025\"\ntime=\"%s\" level=info msg=\"[http] accessible via http://localhost:8025/\"\n", gmdate('Y/m/d H:i:s'), gmdate('Y/m/d H:i:s'), gmdate('Y/m/d H:i:s')));

                return;
            case \in_array($name, ['traefik', 'rabbitmq-server', 'memcached', 'supervisord', 'crond', 'cron'], true):
                $ports = array_map(static fn ($p) => (int) $p, $image->config->exposed);
                $this->listen($container, $name === 'memcached' ? 'memcached' : 'static', $ports);
                $container->processOptions['docroot'] = $image->docroot ?? '/';
                $this->appendLogs($container, $name === 'supervisord' ? "INFO supervisord started with pid 1\n" : '');

                return;
            case $name === 'sleep' && (\in_array($args[0] ?? '', ['infinity', 'inf'], true) || (int) ($args[0] ?? 0) > 60):
            case $name === 'tail' && \in_array('-f', $args, true):
                $this->listen($container, 'idle', []);

                return;
            // Un shell sans argument (ou « php -a ») attend un terminal ; « php -i » est une commande comme une autre.
            case \in_array($name, ['sh', 'bash', 'ash', 'node', 'python3'], true) && ($args === [] || $args === ['-i']):
            case $name === 'php' && ($args === [] || $args === ['-a']):
                if (!$machine->facts->hasBinary($name) && $name !== 'sh') {
                    throw $this->notFound($argv[0]);
                }
                // Un shell interactif sans terminal attaché se termine aussitôt ; avec -it, il attend.
                if ($container->tty) {
                    $this->listen($container, 'idle', []);
                    if ($name === 'php') {
                        $this->appendLogs($container, "Interactive shell\n\n");
                    }

                    return;
                }
                $this->exited($container, 0);

                return;
            case $name === 'php' && isset($args[0]) && !str_starts_with($args[0], '-'):
                $this->runPhpScript($container, $machine, $args);

                return;
        }

        // Commande ponctuelle : exécutée comme dans un conteneur, puis le conteneur s'arrête.
        if (!$machine->facts->hasBinary($name) && !\in_array($name, ['echo', 'true', 'false', 'test', '['], true) && $this->docker->findInPath($machine, $argv[0]) === null) {
            throw $this->notFound($argv[0]);
        }
        $machine->output = '';
        $code = $this->docker->shell->runArgv($argv, $machine);
        $this->appendLogs($container, $machine->output);
        $this->exited($container, $code);
    }

    private function notFound(string $command): DockerException
    {
        return new DockerException(sprintf('failed to create task for container: failed to create shim task: OCI runtime create failed: runc create failed: unable to start container process: error during container init: exec: "%s": executable file not found in $PATH: unknown', $command), 127);
    }

    /** @param list<int> $ports */
    private function listen(Container $container, string $process, array $ports, array $addresses = []): void
    {
        $container->status = Container::RUNNING;
        $container->exitCode = 0;
        $container->process = $process;
        $container->listening = array_values(array_unique($ports));
        $container->listenAddresses = $addresses;
        $container->startedAt = time();
    }

    private function exited(Container $container, int $code): void
    {
        $container->process = null;
        $container->listening = [];
        $container->exitCode = $code;
        $container->finishedAt = time();
        $container->startedAt = $container->startedAt ?: time();
        $restart = $container->restart;
        if ($restart === 'always' || $restart === 'unless-stopped' || (str_starts_with($restart, 'on-failure') && $code !== 0)) {
            $container->status = Container::RESTARTING;
            ++$container->restartCount;

            return;
        }
        $container->status = Container::EXITED;
    }

    public function appendLogs(Container $container, string $text): void
    {
        foreach (explode("\n", rtrim($text, "\n")) as $line) {
            if ($text !== '') {
                $container->logs[] = $line;
            }
        }
        if (\count($container->logs) > 500) {
            $container->logs = \array_slice($container->logs, -500);
        }
    }

    /** @param list<string> $phpWarnings */
    private function startApache(Container $container, Machine $machine, array $phpWarnings): void
    {
        // Les Listen des blocs <IfModule ssl_module> ne comptent que si le module est chargé.
        $portsConf = ApacheServer::applyIfModule((string) $machine->fs->read('/etc/apache2/ports.conf'), [...$machine->facts->apacheModules, 'mime', 'dir', 'alias', 'php']);
        preg_match_all('/^\s*Listen\s+(?:[\d.]+:)?(\d+)/mi', $portsConf, $matches);
        $ports = array_values(array_unique(array_map('intval', $matches[1]))) ?: [80];
        $ip = reset($container->ips) ?: '172.17.0.2';
        $this->appendLogs($container, implode("\n", $phpWarnings));
        // Docker Engine (>= 20.10) règle net.ipv4.ip_unprivileged_port_start à 0 dans les conteneurs :
        // un utilisateur ordinaire y ouvre le port 80. Seul le réseau de l'hôte garde la limite.
        if (!$machine->isRoot() && min($ports) < 1024 && isset($container->networks['host'])) {
            $this->appendLogs($container, sprintf("(13)Permission denied: AH00072: make_sock: could not bind to address [::]:%d\n(13)Permission denied: AH00072: make_sock: could not bind to address 0.0.0.0:%d\nno listening sockets available, shutting down\nAH00015: Unable to open logs", min($ports), min($ports)));
            $this->exited($container, 1);

            return;
        }
        [$root, $warnings] = (new ApacheServer($this->docker))->documentRoot($container, $ports[0]);
        $this->appendLogs($container, implode("\n", $warnings));
        $serverName = false;
        foreach ([(string) $machine->fs->read('/etc/apache2/apache2.conf'), ...ApacheServer::enabled($machine->fs, 'conf'), ...ApacheServer::enabled($machine->fs, 'sites')] as $content) {
            if (preg_match('/^\s*ServerName\s+/mi', $content)) {
                $serverName = true;
            }
        }
        if (!$serverName) {
            $line = sprintf("AH00558: apache2: Could not reliably determine the server's fully qualified domain name, using %s. Set the 'ServerName' directive globally to suppress this message", $ip);
            $this->appendLogs($container, $line."\n".$line);
        }
        $date = gmdate('D M d H:i:s.u Y');
        $this->appendLogs($container, sprintf("[%s] [mpm_prefork:notice] [pid 1:tid 1] AH00163: Apache/2.4.65 (Debian) PHP/%s configured -- resuming normal operations\n[%s] [core:notice] [pid 1:tid 1] AH00094: Command line: 'apache2 -D FOREGROUND'", $date, $container->env['PHP_VERSION'] ?? '8.4.11', $date));
        $this->listen($container, 'apache', $ports);
        $container->processOptions['docroot'] = $root;
    }

    /**
     * Un signal envoyé au processus n° 1 depuis le conteneur (docker compose exec app kill 1).
     * Les serveurs s'arrêtent proprement sur TERM, INT ou QUIT ; un shell ou un sleep, sans gestionnaire,
     * les ignore ; et KILL, envoyé de l'intérieur, n'a aucun effet sur le processus n° 1 (le noyau le protège).
     * Une politique de redémarrage relance aussitôt un conteneur arrêté ainsi.
     */
    public function signal(Container $container, Machine $machine, string $signal): void
    {
        if (!$container->isRunning()) {
            return;
        }
        if ($signal === 'HUP') {
            if ($container->process === 'nginx') {
                $this->reloadNginx($container, $machine);
            }

            return;
        }
        $servers = ['apache', 'nginx', 'php-fpm', 'postgres', 'mysql', 'mariadb', 'redis', 'php-server', 'frankenphp', 'static', 'mailpit'];
        if (!\in_array($signal, ['TERM', 'INT', 'QUIT'], true) || !\in_array($container->process, $servers, true)) {
            return;
        }
        $date = gmdate('d-M-Y H:i:s');
        $this->appendLogs($container, match ($container->process) {
            'php-fpm' => sprintf("[%s] NOTICE: Terminating ...\n[%s] NOTICE: exiting, bye-bye!", $date, $date),
            'nginx' => sprintf("%s [notice] 1#1: signal 15 (SIGTERM) received, exiting\n%s [notice] 1#1: exit", gmdate('Y/m/d H:i:s'), gmdate('Y/m/d H:i:s')),
            'apache' => sprintf('[%s] [mpm_prefork:notice] [pid 1:tid 1] AH00169: caught SIGTERM, shutting down', gmdate('D M d H:i:s.u Y')),
            'postgres' => sprintf("%s UTC [1] LOG:  received fast shutdown request\n%s UTC [1] LOG:  database system is shut down", gmdate('Y-m-d H:i:s.v'), gmdate('Y-m-d H:i:s.v')),
            default => '',
        });
        $this->exited($container, 0);
        if ($container->status === Container::RESTARTING) {
            $this->docker->start($container);
        }
    }

    /**
     * « nginx -s reload » dans le conteneur : nginx relit sa configuration. Si elle est fausse, il
     * garde l'ancienne et le dit dans son journal ; sinon les requêtes suivantes suivent la nouvelle.
     */
    public function reloadNginx(Container $container, Machine $machine): void
    {
        if ($container->process !== 'nginx') {
            return;
        }
        $date = gmdate('Y/m/d H:i:s');
        $this->appendLogs($container, sprintf('%s [notice] 1#1: signal 1 (SIGHUP) received from 45, reconfiguring', $date));
        $config = NginxConfig::load($machine->fs, $this->docker->network($container));
        if ($config->problem() !== null) {
            $this->appendLogs($container, sprintf('%s [emerg] 1#1: %s', $date, $config->problem()));

            return;
        }
        $container->processOptions['nginxConfig'] = ConfigSnapshot::take($machine->fs);
        $this->appendLogs($container, sprintf("%s [notice] 1#1: reconfiguring\n%s [notice] 1#1: start worker processes\n%s [notice] 1#1: start worker process 46\n%s [notice] 29#29: gracefully shutting down\n%s [notice] 29#29: exiting\n%s [notice] 29#29: exit", $date, $date, $date, $date, $date, $date));
    }

    /** @param list<string> $args */
    private function startNginx(Container $container, Machine $machine, array $args): void
    {
        $foreground = str_contains(implode(' ', $args), 'daemon off') || preg_match('/^\s*daemon\s+off\s*;/mi', (string) $machine->fs->read('/etc/nginx/nginx.conf'));
        $prefix = "/docker-entrypoint.sh: /docker-entrypoint.d/ is not empty, will attempt to perform configuration\n/docker-entrypoint.sh: Looking for shell scripts in /docker-entrypoint.d/\n/docker-entrypoint.sh: Launching /docker-entrypoint.d/10-listen-on-ipv6-by-default.sh\n10-listen-on-ipv6-by-default.sh: info: Getting the checksum of /etc/nginx/conf.d/default.conf\n/docker-entrypoint.sh: Launching /docker-entrypoint.d/20-envsubst-on-templates.sh\n/docker-entrypoint.sh: Launching /docker-entrypoint.d/30-tune-worker-processes.sh\n/docker-entrypoint.sh: Configuration complete; ready for start up\n";
        if (!$machine->isRoot() && !$this->docker->shell->canWrite($machine, '/etc/nginx/conf.d/default.conf')) {
            $prefix = str_replace("10-listen-on-ipv6-by-default.sh: info: Getting the checksum of /etc/nginx/conf.d/default.conf\n", "10-listen-on-ipv6-by-default.sh: info: can not modify /etc/nginx/conf.d/default.conf (read-only file system?)\n", $prefix);
        }
        $this->appendLogs($container, $prefix);
        $config = NginxConfig::load($machine->fs, $this->docker->network($container));
        $date = gmdate('Y/m/d H:i:s');
        // nginx -v, nginx -t (docker compose run --rm web nginx -t) : il répond, puis le conteneur s'arrête.
        if (\in_array('-v', $args, true) || \in_array('-V', $args, true)) {
            $this->appendLogs($container, 'nginx version: nginx/1.29.1');
            $this->exited($container, 0);

            return;
        }
        if (\in_array('-t', $args, true) || \in_array('-T', $args, true)) {
            if ($config->problem() !== null) {
                $this->appendLogs($container, sprintf("nginx: [emerg] %s\nnginx: configuration file /etc/nginx/nginx.conf test failed", $config->problem()));
                $this->exited($container, 1);

                return;
            }
            $this->appendLogs($container, "nginx: the configuration file /etc/nginx/nginx.conf syntax is ok\nnginx: configuration file /etc/nginx/nginx.conf test is successful");
            $this->exited($container, 0);

            return;
        }
        if ($config->problem() !== null) {
            $this->appendLogs($container, sprintf("%s [emerg] 1#1: %s\nnginx: [emerg] %s", $date, $config->problem(), $config->problem()));
            $this->exited($container, 1);

            return;
        }
        if (!$foreground) {
            // nginx passe en arrière-plan : le processus principal se termine, le conteneur aussi.
            $this->exited($container, 0);

            return;
        }
        $ports = $config->ports() ?: [80];
        if (!$machine->isRoot()) {
            // Sans root, nginx ne peut ni changer d'utilisateur, ni créer ses dossiers temporaires dans un
            // dossier de root, ni écrire son pid dans /run : c'est à ça que sert l'image nginx-unprivileged.
            $main = (string) $machine->fs->read('/etc/nginx/nginx.conf');
            if (preg_match('/^\s*user\s+[^;]+;/m', $main, $m, \PREG_OFFSET_CAPTURE)) {
                $line = substr_count(substr($main, 0, $m[0][1]), "\n") + 1;
                $this->appendLogs($container, sprintf('nginx: [warn] the "user" directive makes sense only if the master process runs with super-user privileges, ignored in /etc/nginx/nginx.conf:%d', $line));
            }
            $temp = preg_match('/^\s*client_body_temp_path\s+([^\s;]+)/m', $main, $t) ? $t[1] : '/var/cache/nginx/client_temp';
            if (!$machine->fs->isDir($temp) && !$this->docker->shell->canWrite($machine, \dirname($temp))) {
                $this->appendLogs($container, sprintf("%s [emerg] 1#1: mkdir() \"%s\" failed (13: Permission denied)\nnginx: [emerg] mkdir() \"%s\" failed (13: Permission denied)", $date, $temp, $temp));
                $this->exited($container, 1);

                return;
            }
        }
        if (!$machine->isRoot() && min($ports) < 1024 && isset($container->networks['host'])) {
            $this->appendLogs($container, sprintf("%s [emerg] 1#1: bind() to 0.0.0.0:%d failed (13: Permission denied)\nnginx: [emerg] bind() to 0.0.0.0:%d failed (13: Permission denied)", $date, min($ports), min($ports)));
            $this->exited($container, 1);

            return;
        }
        $this->appendLogs($container, sprintf("%s [notice] 1#1: using the \"epoll\" event method\n%s [notice] 1#1: nginx/1.29.1\n%s [notice] 1#1: built by gcc 14.2.0 (".($machine->facts->os === 'alpine' ? 'Alpine 14.2.0' : 'Debian 14.2.0-19').")\n%s [notice] 1#1: OS: Linux 6.10.14-linuxkit\n%s [notice] 1#1: start worker processes\n%s [notice] 1#1: start worker process 29", $date, $date, $date, $date, $date, $date));
        if (!$machine->isRoot()) {
            $pid = preg_match('/^\s*pid\s+([^\s;]+)/m', (string) $machine->fs->read('/etc/nginx/nginx.conf'), $pm) ? $pm[1] : '/run/nginx.pid';
            if (!$this->docker->shell->canWrite($machine, $pid)) {
                $this->appendLogs($container, sprintf("%s [emerg] 1#1: open() \"%s\" failed (13: Permission denied)\nnginx: [emerg] open() \"%s\" failed (13: Permission denied)", $date, $pid, $pid));
                $this->exited($container, 1);

                return;
            }
        }
        $this->listen($container, 'nginx', $ports);
        // nginx garde la configuration lue maintenant, jusqu'au prochain redémarrage ou « nginx -s reload ».
        $container->processOptions['nginxConfig'] = ConfigSnapshot::take($machine->fs);
    }

    /** @param list<string> $phpWarnings */
    private function startFpm(Container $container, Machine $machine, array $phpWarnings): void
    {
        $address = '0.0.0.0';
        $port = 9000;
        // include=etc/php-fpm.d/*.conf : les fichiers en .conf seulement, par ordre alphabétique ; le dernier « listen » gagne.
        $pool = array_values(array_filter($machine->fs->list('/usr/local/etc/php-fpm.d'), static fn ($f) => str_ends_with($f, '.conf')));
        sort($pool, \SORT_STRING);
        $poolUserSet = false;
        foreach (array_map(static fn ($f) => '/usr/local/etc/php-fpm.d/'.$f, $pool) as $file) {
            $content = (string) $machine->fs->read($file);
            $poolUserSet = $poolUserSet || preg_match('/^\s*user\s*=/mi', $content) === 1;
            if (preg_match_all('/^\s*listen\s*=\s*(?:([\d.]+|\[::\]|localhost):)?(\d+)\s*$/mi', $content, $m, PREG_SET_ORDER)) {
                $last = end($m);
                $port = (int) $last[2];
                $address = $last[1] !== '' ? $last[1] : '0.0.0.0';
            }
        }
        $date = gmdate('d-M-Y H:i:s');
        $this->appendLogs($container, implode("\n", $phpWarnings));
        if (!$machine->isRoot() && $poolUserSet) {
            $this->appendLogs($container, sprintf("[%s] NOTICE: [pool www] 'user' directive is ignored when FPM is not running as root\n[%s] NOTICE: [pool www] 'group' directive is ignored when FPM is not running as root", $date, $date));
        }
        $this->appendLogs($container, sprintf("[%s] NOTICE: fpm is running, pid 1\n[%s] NOTICE: ready to handle connections", $date, $date));
        $this->listen($container, 'php-fpm', [$port], [$port => $address]);
    }

    /**
     * @param list<string> $args
     * @param list<string> $phpWarnings
     */
    private function startBuiltinServer(Container $container, Machine $machine, array $args, array $phpWarnings): void
    {
        $listen = $args[1] ?? '';
        if (!preg_match('/^(.*):(\d+)$/', $listen, $m)) {
            $this->appendLogs($container, "Invalid address: {$listen}");
            $this->exited($container, 1);

            return;
        }
        $docroot = $container->workdir;
        $router = null;
        for ($i = 2; $i < \count($args); ++$i) {
            if ($args[$i] === '-t') {
                $docroot = $machine->path($args[++$i] ?? '.');
            } elseif (!str_starts_with($args[$i], '-')) {
                $router = $machine->path($args[$i]);
            }
        }
        $this->appendLogs($container, implode("\n", $phpWarnings));
        if (!$machine->fs->isDir($docroot)) {
            $this->appendLogs($container, sprintf('Directory %s does not exist.', $args[array_search('-t', $args, true) + 1] ?? $docroot));
            $this->exited($container, 1);

            return;
        }
        $this->appendLogs($container, sprintf('[%s] PHP %s Development Server (http://%s) started', gmdate('D M j H:i:s Y'), $container->env['PHP_VERSION'] ?? '8.4.11', $listen));
        $address = $m[1] === '' ? '0.0.0.0' : $m[1];
        $this->listen($container, 'php-server', [(int) $m[2]], [(int) $m[2] => \in_array($address, ['localhost', '127.0.0.1'], true) ? '127.0.0.1' : '0.0.0.0']);
        $container->processOptions['docroot'] = $docroot;
        $container->processOptions['router'] = $router;
    }

    /** @param list<string> $args */
    private function runPhpScript(Container $container, Machine $machine, array $args): void
    {
        $script = $machine->path($args[0]);
        if (!$machine->fs->isFile($script)) {
            $this->appendLogs($container, 'Could not open input file: '.$args[0]);
            $this->exited($container, 1);

            return;
        }
        $joined = implode(' ', $args);
        if (preg_match('/(messenger:consume|queue:work|schedule:work|horizon|reverb:start|octane:start)/', $joined)) {
            $this->appendLogs($container, sprintf("[OK] Consuming messages from transport \"async\".\n // (simulateur : le processus tourne en continu, les messages ne sont pas traités)"));
            $this->listen($container, 'worker', []);

            return;
        }
        [$code, $output] = $this->docker->php()->cli($container, [$script, ...\array_slice($args, 1)], $container->workdir, $container->env);
        $this->appendLogs($container, $output);
        $this->exited($container, $code);
    }

    /** @param list<string> $args */
    private function startPostgres(Container $container, Machine $machine, array $args): void
    {
        $env = $container->env;
        $pgdata = $env['PGDATA'] ?? '/var/lib/postgresql/data';
        $initialized = $machine->fs->isFile($pgdata.'/PG_VERSION');
        // PostgreSQL 18 : des données à l'ancien emplacement (souvent un volume de la 17) font refuser le démarrage.
        $major = (string) ($env['PG_MAJOR'] ?? '');
        if (!$initialized && $pgdata === '/var/lib/postgresql/'.$major.'/docker') {
            $old = array_values(array_filter(['/var/lib/postgresql', '/var/lib/postgresql/data'], static fn ($d) => $machine->fs->isFile($d.'/PG_VERSION')));
            if ($old !== []) {
                $this->appendLogs($container, "Error: in 18+, these Docker images are configured to store database data in a\n       format which is compatible with \"pg_ctlcluster\" (specifically, using\n       major-version-specific directory names).  This better reflects how\n       PostgreSQL itself works, and how upgrades are to be performed.\n\n       See also https://github.com/docker-library/postgres/pull/1259\n\n       Counter to that, there appears to be PostgreSQL data in:\n         ".implode(' ', $old)."\n\n       This is usually the result of upgrading the Docker image without\n       upgrading the underlying database using \"pg_upgrade\" (which requires both\n       versions).\n\n       The suggested container configuration for 18+ is to place a single mount\n       at /var/lib/postgresql which will then place PostgreSQL data in a\n       subdirectory, allowing usage of \"pg_upgrade --link\" without mount point\n       boundary issues.\n\n       See https://github.com/docker-library/postgres/issues/37 for a (long)\n       discussion around this process, and suggestions for how to do so.");
                $this->exited($container, 1);

                return;
            }
        }
        $date = gmdate('Y-m-d H:i:s.v').' UTC';
        if (!$initialized) {
            if (($env['POSTGRES_PASSWORD'] ?? '') === '' && ($env['POSTGRES_HOST_AUTH_METHOD'] ?? '') !== 'trust') {
                $this->appendLogs($container, "Error: Database is uninitialized and superuser password is not specified.\n       You must specify POSTGRES_PASSWORD to a non-empty value for the\n       superuser. For example, \"-e POSTGRES_PASSWORD=password\" on \"docker run\".\n\n       You may also use \"POSTGRES_HOST_AUTH_METHOD=trust\" to allow all\n       connections without a password. This is *not* recommended.\n\n       See PostgreSQL documentation about \"trust\":\n       https://www.postgresql.org/docs/current/auth-trust.html");
                $this->exited($container, 1);

                return;
            }
            $user = $env['POSTGRES_USER'] ?? 'postgres';
            $database = $env['POSTGRES_DB'] ?? $user;
            $machine->fs->mkdir($pgdata.'/base');
            $machine->fs->write($pgdata.'/PG_VERSION', ($env['PG_MAJOR'] ?? '18')."\n");
            $machine->fs->write($pgdata.'/sim-databases.json', json_encode(['user' => $user, 'database' => $database, 'password' => $env['POSTGRES_PASSWORD'] ?? '']));
            $this->appendLogs($container, sprintf("The files belonging to this database system will be owned by user \"postgres\".\nThis user must also own the server process.\n\nThe database cluster will be initialized with locale \"en_US.utf8\".\nThe default database encoding has accordingly been set to \"UTF8\".\n\nfixing permissions on existing directory %s ... ok\ncreating subdirectories ... ok\nperforming post-bootstrap initialization ... ok\nsyncing data to disk ... ok\n\nSuccess. You can now start the database server using:\n\n    pg_ctl -D %s -l logfile start\n\nwaiting for server to start....%s [48] LOG:  database system is ready to accept connections\n done\nserver started\n%s\n\n/usr/local/bin/docker-entrypoint.sh: ignoring /docker-entrypoint-initdb.d/*\n\nwaiting for server to shut down.... done\nserver stopped\n\nPostgreSQL init process complete; ready for start up.\n", $pgdata, $pgdata, $date, $database !== 'postgres' ? 'CREATE DATABASE' : ''));
        } else {
            $this->appendLogs($container, "\nPostgreSQL Database directory appears to contain a database; Skipping initialization\n");
            // Les identifiants sont ceux de l'initialisation : changer POSTGRES_PASSWORD ensuite n'y fait rien.
            $saved = json_decode((string) $machine->fs->read($pgdata.'/sim-databases.json'), true) ?: [];
            if ($saved !== []) {
                $container->processOptions['postgres'] = $saved;
            }
        }
        $this->appendLogs($container, sprintf("%s [1] LOG:  starting PostgreSQL %s on x86_64-pc-linux-gnu, compiled by gcc (Debian 14.2.0-19) 14.2.0, 64-bit\n%s [1] LOG:  listening on IPv4 address \"0.0.0.0\", port 5432\n%s [1] LOG:  listening on IPv6 address \"::\", port 5432\n%s [1] LOG:  listening on Unix socket \"/var/run/postgresql/.s.PGSQL.5432\"\n%s [1] LOG:  database system is ready to accept connections", $date, $env['PG_VERSION'] ?? '18.0', $date, $date, $date, $date));
        $this->listen($container, 'postgres', [5432]);
    }

    private function startMysql(Container $container, Machine $machine, string $binary): void
    {
        $env = $container->env;
        $prefix = $binary === 'mariadbd' ? 'MARIADB' : 'MYSQL';
        $initialized = $machine->fs->isFile('/var/lib/mysql/ibdata1');
        $date = gmdate('Y-m-d H:i:sP');
        if (!$initialized) {
            $has = static fn (string $key) => ($env[$prefix.'_'.$key] ?? ($env['MYSQL_'.$key] ?? '')) !== '';
            if (!$has('ROOT_PASSWORD') && !$has('ALLOW_EMPTY_PASSWORD') && !$has('RANDOM_ROOT_PASSWORD') && !$has('ALLOW_EMPTY_ROOT_PASSWORD') && !$has('RANDOM_ROOT_PASSWORD')) {
                $this->appendLogs($container, sprintf("%s [Note] [Entrypoint]: Entrypoint script for %s Server started.\n%s [ERROR] [Entrypoint]: Database is uninitialized and password option is not specified\n    You need to specify one of the following as an environment variable:\n    - %s_ROOT_PASSWORD\n    - %s_ALLOW_EMPTY%s_PASSWORD\n    - %s_RANDOM_ROOT_PASSWORD", $date, $binary === 'mariadbd' ? 'MariaDB' : 'MySQL', $date, $prefix, $prefix, $prefix === 'MARIADB' ? '_ROOT' : '', $prefix));
                $this->exited($container, 1);

                return;
            }
            $machine->fs->write('/var/lib/mysql/ibdata1', '');
            $this->appendLogs($container, sprintf("%s [Note] [Entrypoint]: Initializing database files\n%s [Note] [Entrypoint]: Database files initialized\n%s [Note] [Entrypoint]: MySQL init process done. Ready for start up.", $date, $date, $date));
        }
        $this->appendLogs($container, sprintf("%s 0 [System] [MY-010931] [Server] /usr/sbin/mysqld: ready for connections. Version: '%s'  socket: '/var/run/mysqld/mysqld.sock'  port: 3306  MySQL Community Server - GPL.", $date, $env['MYSQL_VERSION'] ?? ($env['MARIADB_VERSION'] ?? '8.4.6')));
        $this->listen($container, $binary === 'mariadbd' ? 'mariadb' : 'mysql', [3306, 33060]);
    }

    /**
     * Le healthcheck, joué au démarrage puis à chaque fois qu'on regarde l'état du conteneur (docker ps,
     * inspect, compose ps) : réparer l'application le rend sain, la casser le rend malade. Le temps est
     * comprimé comme partout dans le simulateur : un échec vaut les « retries » échecs d'affilée.
     */
    public function health(Container $container): void
    {
        $test = $container->healthcheck['test'] ?? null;
        if ($test === null || $test === [] || ($test[0] ?? '') === 'NONE') {
            $container->health = null;

            return;
        }
        $machine = $this->docker->machine($container);
        $machine->output = '';
        $kind = array_shift($test);
        $start = microtime(true);
        $code = $kind === 'CMD-SHELL' ? $this->docker->shell->run(implode(' ', $test), $machine) : $this->docker->shell->runArgv($test, $machine);
        $container->healthLog = $machine->output;
        $retries = (int) ($container->healthcheck['retries'] ?? 3);
        $container->processOptions['failingStreak'] = $code === 0 ? 0 : max($retries, 1);
        $log = \is_array($container->processOptions['healthRuns'] ?? null) ? $container->processOptions['healthRuns'] : [];
        $log[] = ['Start' => gmdate('Y-m-d\\TH:i:s', (int) $start).'.000000000Z', 'End' => gmdate('Y-m-d\\TH:i:s').'.000000000Z', 'ExitCode' => $code === 0 ? 0 : 1, 'Output' => $machine->output];
        $container->processOptions['healthRuns'] = \array_slice($log, -5);
        $container->health = $code === 0 ? 'healthy' : 'unhealthy';
        $this->docker->saveFacts($container, $machine->facts);
    }
}
