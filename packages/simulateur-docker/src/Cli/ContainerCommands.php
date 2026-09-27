<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Cli;

use Forelse\DockerSim\Engine\ContainerSpec;
use Forelse\DockerSim\Engine\Docker;
use Forelse\DockerSim\Engine\DockerException;
use Forelse\DockerSim\Engine\PortSyntax;
use Forelse\DockerSim\Engine\VolumeSyntax;
use Forelse\DockerSim\Fs\Path;
use Forelse\DockerSim\State\Container;
use Forelse\DockerSim\State\ProcessKind;
use Forelse\DockerSim\State\Store;

/** docker run, create, start, stop, kill, restart, rm, ps, container …, logs, exec, port, top, cp. */
final class ContainerCommands implements CliCommand
{
    private Output $out;

    public function __construct(private readonly Docker $docker, private readonly InspectCommand $inspect)
    {
    }

    public function names(): array
    {
        return ['run', 'create', 'start', 'stop', 'kill', 'restart', 'rm', 'ps', 'container', 'logs', 'exec', 'port', 'top', 'cp'];
    }

    public function run(string $name, array $argv, Output $out): int
    {
        $this->out = $out;

        return match ($name) {
            'run' => $this->runContainer($argv),
            'create' => $this->runContainer($argv, createOnly: true),
            'start' => $this->start($argv),
            'stop', 'kill' => $this->stop($argv, $name),
            'restart' => $this->restart($argv),
            'rm' => $this->rm($argv),
            'ps' => $this->ps($argv),
            'container' => $this->container($argv),
            'logs' => $this->logs($argv),
            'exec' => $this->exec($argv),
            'port' => $this->port($argv),
            'top' => $this->top($argv),
            'cp' => $this->cp($argv),
            default => throw new \LogicException(sprintf('%s : « %s » est déclarée sans implémentation.', self::class, $name)),
        };
    }

    private const RUN_SPEC = [
        'd' => ['detach', false], 'detach' => ['detach', false], 'i' => ['interactive', false], 'interactive' => ['interactive', false],
        't' => ['tty', false], 'tty' => ['tty', false], 'rm' => ['rm', false], 'name' => ['name', true],
        'p' => ['publish', true, true], 'publish' => ['publish', true, true], 'P' => ['publish-all', false], 'publish-all' => ['publish-all', false],
        'e' => ['env', true, true], 'env' => ['env', true, true], 'env-file' => ['env-file', true, true],
        'v' => ['volume', true, true], 'volume' => ['volume', true, true], 'mount' => ['mount', true, true],
        'network' => ['network', true], 'net' => ['network', true], 'network-alias' => ['network-alias', true, true],
        'w' => ['workdir', true], 'workdir' => ['workdir', true], 'u' => ['user', true], 'user' => ['user', true],
        'entrypoint' => ['entrypoint', true], 'restart' => ['restart', true], 'hostname' => ['hostname', true], 'h' => ['hostname', true],
        'l' => ['label', true, true], 'label' => ['label', true, true], 'health-cmd' => ['health-cmd', true], 'health-interval' => ['health-interval', true],
        'health-retries' => ['health-retries', true], 'health-timeout' => ['health-timeout', true], 'health-start-period' => ['health-start-period', true], 'no-healthcheck' => ['no-healthcheck', false],
        'init' => ['init', false], 'platform' => ['platform', true], 'pull' => ['pull', true], 'memory' => ['memory', true], 'm' => ['memory', true],
        'cpus' => ['cpus', true], 'add-host' => ['add-host', true, true], 'privileged' => ['privileged', false], 'read-only' => ['read-only', false],
        'expose' => ['expose', true, true], 'link' => ['link', true, true], 'stop-signal' => ['stop-signal', true], 'q' => ['quiet', false], 'quiet' => ['quiet', false],
    ];

    /** @param list<string> $argv */
    private function runContainer(array $argv, bool $createOnly = false): int
    {
        $command = $createOnly ? 'create' : 'run';
        try {
            $args = Args::parse($argv, self::RUN_SPEC, $command, stopAtPositional: true);
        } catch (UsageError $e) {
            throw new UsageError($e->getMessage(), 125);
        }
        if ($args->positional === []) {
            throw new UsageError(sprintf("'docker %s' requires at least 1 argument\n\nUsage:  docker %s [OPTIONS] IMAGE [COMMAND] [ARG...]\n\nSee 'docker %s --help' for more information", $command, $command, $command), 1);
        }
        $reference = array_shift($args->positional);
        if (preg_match('/[A-Z]/', explode(':', $reference)[0]) || str_starts_with($reference, '-')) {
            $this->out->line(sprintf("docker: invalid reference format: repository name (library/%s) must be lowercase\n\nRun 'docker %s --help' for more information", $reference, $command));

            return 125;
        }
        $spec = new ContainerSpec($reference);
        $spec->name = $args->get('name');
        $spec->command = $args->positional === [] ? null : $args->positional;
        if ($args->get('entrypoint') !== null) {
            $spec->entrypoint = $args->get('entrypoint') === '' ? [] : [(string) $args->get('entrypoint')];
        }
        foreach ($args->all('env-file') as $file) {
            $path = $this->docker->hostPath($file);
            if (!is_file($path)) {
                $this->out->line(sprintf("docker: open %s: no such file or directory\n\nRun 'docker %s --help' for more information", $file, $command));

                return 125;
            }
            $spec->env = \Forelse\DockerSim\Compose\EnvFile::load($path) + $spec->env;
        }
        foreach ($args->all('env') as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, null);
            if ($value !== null) {
                $spec->env[$key] = $value;
            }
        }
        try {
            foreach ($args->all('publish') as $publish) {
                foreach (PortSyntax::parse($publish) as $port) {
                    // Sans port de l'hôte, Docker en choisit un libre parmi les ports éphémères.
                    $spec->ports[] = ['host' => $port['host'] ?? 32768 + random_int(0, 20000)] + $port;
                }
            }
            foreach ($args->all('volume') as $volume) {
                $spec->mounts[] = VolumeSyntax::parse($volume, $this->docker->hostPath(...));
            }
        } catch (DockerException $e) {
            $this->out->line(sprintf("docker: %s%s\n\nRun 'docker %s --help' for more information", $e->daemon ? 'Error response from daemon: ' : '', $e->getMessage(), $command));

            return 125;
        }
        $spec->publishAll = $args->has('publish-all');
        foreach ($args->all('mount') as $mount) {
            $options = [];
            foreach (explode(',', $mount) as $pair) {
                [$key, $value] = array_pad(explode('=', $pair, 2), 2, 'true');
                $options[$key] = $value;
            }
            $type = $options['type'] ?? 'volume';
            $source = $options['source'] ?? ($options['src'] ?? '');
            if ($type === 'bind' && !file_exists($this->docker->hostPath($source))) {
                $this->out->line(sprintf("docker: Error response from daemon: invalid mount config for type \"bind\": bind source path does not exist: %s\n\nRun 'docker %s --help' for more information", $this->docker->hostPath($source), $command));

                return 125;
            }
            $spec->mounts[] = ['type' => $type === 'bind' ? 'bind' : 'volume', 'source' => $source, 'target' => $options['target'] ?? ($options['dst'] ?? ($options['destination'] ?? '')), 'readOnly' => isset($options['readonly']) || isset($options['ro'])];
        }
        if ($args->get('network') !== null) {
            $spec->networks[(string) $args->get('network')] = $args->all('network-alias');
        }
        $spec->workdir = $args->get('workdir');
        $spec->user = $args->get('user');
        $spec->restart = (string) $args->get('restart', 'no');
        $spec->hostname = $args->get('hostname');
        $spec->autoRemove = $args->has('rm');
        $spec->tty = $args->has('tty') && $args->has('interactive');
        $spec->interactive = $args->has('interactive');
        foreach ($args->all('label') as $label) {
            [$key, $value] = array_pad(explode('=', $label, 2), 2, '');
            $spec->labels[$key] = $value;
        }
        if ($args->get('health-cmd') !== null) {
            $spec->healthcheck = ['test' => ['CMD-SHELL', (string) $args->get('health-cmd')]];
        }
        if ($args->has('no-healthcheck')) {
            $spec->healthcheck = ['test' => ['NONE']];
        }

        $pullOutput = '';
        try {
            $container = $this->docker->create($spec, $pullOutput);
        } catch (DockerException $e) {
            $this->out->write($pullOutput);
            $this->out->line(sprintf("docker: Error response from daemon: %s\n\nRun 'docker %s --help' for more information", $e->getMessage(), $command));

            return 125;
        }
        $this->out->write($pullOutput);
        if ($createOnly) {
            $this->out->line($container->id);

            return 0;
        }
        try {
            $this->docker->start($container);
        } catch (DockerException $e) {
            if ($args->has('detach')) {
                $this->out->line($container->id);
            }
            $this->out->line(sprintf("docker: Error response from daemon: %s\n\nRun 'docker run --help' for more information", $e->getMessage()));

            return $e->exitCode;
        }
        if ($args->has('detach')) {
            $this->out->line($container->id);

            return 0;
        }
        if ($args->has('interactive') && $args->has('tty') && $container->isRunning() && $container->process === ProcessKind::Idle) {
            $this->out->write(implode("\n", $container->logs).($container->logs !== [] ? "\n" : ''));
            $this->out->line(sprintf('💡 La console du simulateur n\'est pas un terminal : impossible d\'entrer dans le conteneur. Il tourne en arrière-plan (%s) ; lancez vos commandes avec docker exec %s <commande>.', $container->name, $container->name));

            return 0;
        }
        $this->out->write(implode("\n", $container->logs).($container->logs !== [] ? "\n" : ''));
        if ($container->isRunning()) {
            $this->out->line(sprintf('💡 Le conteneur %s tourne. Un vrai terminal resterait attaché à ses journaux (Ctrl+C l\'arrêterait) ; ici la main vous est rendue et il continue en arrière-plan : docker ps, docker logs %s, docker stop %s.', $container->name, $container->name, $container->name));

            return 0;
        }

        return $container->exitCode;
    }

    private function findContainer(string $name): Container
    {
        return $this->docker->findContainer($name) ?? throw new DockerException(sprintf('No such container: %s', $name), 1);
    }

    /** @param list<string> $argv */
    private function start(array $argv): int
    {
        $args = Args::parse($argv, ['a' => ['attach', false], 'attach' => ['attach', false], 'i' => ['interactive', false]], 'start');
        $code = 0;
        foreach ($args->positional as $name) {
            $container = $this->docker->findContainer($name);
            if ($container === null) {
                $this->out->line(sprintf('Error response from daemon: No such container: %s', $name));
                $code = 1;
                continue;
            }
            $before = \count($container->logs);
            try {
                $this->docker->start($container);
            } catch (DockerException $e) {
                $this->out->line(sprintf('Error response from daemon: %s', $e->getMessage()));
                $this->out->line(sprintf('Error: failed to start containers: %s', $name));
                $code = 1;
                continue;
            }
            if ($args->has('attach')) {
                $this->out->write(implode("\n", \array_slice($container->logs, $before))."\n");
                $code = $container->isRunning() ? 0 : $container->exitCode;
            } else {
                $this->out->line($name);
            }
        }

        return $code;
    }

    /** @param list<string> $argv */
    private function stop(array $argv, string $command): int
    {
        $args = Args::parse($argv, ['t' => ['time', true], 'time' => ['time', true], 's' => ['signal', true], 'signal' => ['signal', true]], $command);
        if ($args->positional === []) {
            throw new UsageError(sprintf("\"docker %s\" requires at least 1 argument.\nSee 'docker %s --help'.", $command, $command), 1);
        }
        $code = 0;
        foreach ($args->positional as $name) {
            $container = $this->docker->findContainer($name);
            if ($container === null) {
                $this->out->line(sprintf('Error response from daemon: No such container: %s', $name));
                $code = 1;
                continue;
            }
            if ($command === 'kill' && !$container->isRunning()) {
                $this->out->line(sprintf('Error response from daemon: cannot kill container: %s: container %s is not running', $name, $container->id));
                $code = 1;
                continue;
            }
            if ($command === 'kill') {
                $this->docker->kill($container);
            } else {
                $this->docker->stop($container);
            }
            $this->out->line($name);
        }

        return $code;
    }

    /** @param list<string> $argv */
    private function restart(array $argv): int
    {
        $args = Args::parse($argv, ['t' => ['time', true], 'time' => ['time', true]], 'restart');
        $code = 0;
        foreach ($args->positional as $name) {
            try {
                $this->docker->restart($this->findContainer($name));
                $this->out->line($name);
            } catch (DockerException $e) {
                $this->out->line('Error response from daemon: '.$e->getMessage());
                $code = 1;
            }
        }

        return $code;
    }

    /** @param list<string> $argv */
    private function rm(array $argv): int
    {
        $args = Args::parse($argv, ['f' => ['force', false], 'force' => ['force', false], 'v' => ['volumes', false], 'volumes' => ['volumes', false], 'l' => ['link', false]], 'rm');
        if ($args->positional === []) {
            throw new UsageError("\"docker rm\" requires at least 1 argument.\nSee 'docker rm --help'.\n\nUsage:  docker rm [OPTIONS] CONTAINER [CONTAINER...]\n\nRemove one or more containers", 1);
        }
        $code = 0;
        foreach ($args->positional as $name) {
            $container = $this->docker->findContainer($name);
            if ($container === null) {
                $this->out->line(sprintf('Error response from daemon: No such container: %s', $name));
                $code = 1;
                continue;
            }
            try {
                $this->docker->remove($container, $args->has('force'), $args->has('volumes'));
                $this->out->line($name);
            } catch (DockerException $e) {
                $this->out->line('Error response from daemon: '.$e->getMessage());
                $code = 1;
            }
        }

        return $code;
    }

    /** @param list<string> $argv */
    private function ps(array $argv): int
    {
        $args = Args::parse($argv, ['a' => ['all', false], 'all' => ['all', false], 'q' => ['quiet', false], 'quiet' => ['quiet', false], 'no-trunc' => ['no-trunc', false], 'filter' => ['filter', true, true], 'f' => ['filter', true, true], 's' => ['size', false], 'format' => ['format', true], 'l' => ['latest', false], 'n' => ['last', true]], 'ps');
        $containers = array_values(array_filter($this->docker->containers(), static fn (Container $c) => $args->has('all') || $c->isRunning() || $c->status === Container::RESTARTING));
        foreach ($args->all('filter') as $filter) {
            [$key, $value] = array_pad(explode('=', $filter, 2), 2, '');
            $containers = array_values(array_filter($containers, static fn (Container $c) => match ($key) {
                'name' => str_contains($c->name, $value),
                'status' => $c->status === $value,
                'label' => str_contains($value, '=') ? ($c->labels[explode('=', $value, 2)[0]] ?? null) === explode('=', $value, 2)[1] : isset($c->labels[$value]),
                'ancestor' => $c->imageRef === $value,
                default => true,
            }));
        }
        // Les plus récents d'abord ; à la seconde près, l'ordre de création départage.
        $rank = array_flip(array_keys($this->docker->containers()));
        usort($containers, static fn (Container $a, Container $b) => [$b->createdAt, $rank[$b->id] ?? 0] <=> [$a->createdAt, $rank[$a->id] ?? 0]);
        foreach ($containers as $container) {
            $this->docker->refreshHealth($container);
        }
        if ($args->has('quiet')) {
            $this->out->write(implode('', array_map(static fn (Container $c) => $c->shortId()."\n", $containers)));

            return 0;
        }
        $format = (string) $args->get('format', '');
        if ($format !== '' && $format !== 'table') {
            $table = str_starts_with($format, 'table ');
            $template = str_replace(['\\t', '\\n'], ["\t", "\n"], $table ? substr($format, 6) : $format);
            $fields = fn (Container $c) => ['ID' => $c->shortId(), 'Image' => $this->imageLabel($c), 'Command' => Format::command($c), 'RunningFor' => Format::ago($c->createdAt), 'Status' => Format::status($c), 'State' => $c->isRunning() ? 'running' : $c->status, 'Ports' => Format::ports($c, exposed: $this->docker->imageOf($c)?->config->exposed ?? []), 'Names' => $c->name, 'Networks' => implode(',', array_keys($c->networks))];
            $render = static fn (array $values) => preg_replace_callback('/\{\{\s*\.(\w+)\s*\}\}/', static fn ($m) => (string) ($values[$m[1]] ?? ''), $template);
            if ($table) {
                $this->out->write((string) preg_replace_callback('/\{\{\s*\.(\w+)\s*\}\}/', static fn ($m) => strtoupper((string) preg_replace('/(?<!^)[A-Z]/', ' $0', $m[1])), $template)."\n");
            }
            foreach ($containers as $c) {
                $this->out->write($render($fields($c))."\n");
            }

            return 0;
        }
        $rows = array_map(fn (Container $c) => [$c->shortId(), $this->imageLabel($c), Format::command($c), Format::ago($c->createdAt), Format::status($c), Format::ports($c, exposed: $this->docker->imageOf($c)?->config->exposed ?? []), $c->name], $containers);
        $this->out->write(Format::table(['CONTAINER ID', 'IMAGE', 'COMMAND', 'CREATED', 'STATUS', 'PORTS', 'NAMES'], $rows));

        return 0;
    }

    private function imageLabel(Container $container): string
    {
        $image = $this->docker->imageOf($container);
        if ($image === null) {
            return $container->imageRef;
        }
        $reference = Store::normalizeTag($container->imageRef);
        if (\in_array($reference, $image->tags, true)) {
            return str_ends_with($container->imageRef, ':latest') || !str_contains($container->imageRef, ':') ? preg_replace('/:latest$/', '', $container->imageRef) : $container->imageRef;
        }

        return $image->shortId();
    }

    /** @param list<string> $argv */
    private function container(array $argv): int
    {
        $sub = array_shift($argv);

        return match ($sub) {
            'ls', 'list', 'ps' => $this->ps($argv),
            'run' => $this->runContainer($argv),
            'create' => $this->runContainer($argv, true),
            'start' => $this->start($argv),
            'stop' => $this->stop($argv, 'stop'),
            'kill' => $this->stop($argv, 'kill'),
            'restart' => $this->restart($argv),
            'rm', 'remove' => $this->rm($argv),
            'logs' => $this->logs($argv),
            'exec' => $this->exec($argv),
            'inspect' => $this->inspect->inspect($argv, 'container', $this->out),
            'port' => $this->port($argv),
            'top' => $this->top($argv),
            'cp' => $this->cp($argv),
            'prune' => $this->containerPrune($argv),
            default => Application::unknown($this->out, 'container '.($sub ?? '')),
        };
    }

    /** @param list<string> $argv */
    private function containerPrune(array $argv): int
    {
        $args = Args::parse($argv, ['f' => ['force', false], 'force' => ['force', false], 'filter' => ['filter', true, true]], 'container prune');
        if (!$args->has('force')) {
            $this->out->line('WARNING! This will remove all stopped containers.');
        }
        $deleted = $this->docker->pruneContainers();
        if ($deleted !== []) {
            $this->out->line("Deleted Containers:\n".implode("\n", $deleted)."\n");
        }
        $this->out->line('Total reclaimed space: 0B');

        return 0;
    }

    /** @param list<string> $argv */
    private function logs(array $argv): int
    {
        $args = Args::parse($argv, ['f' => ['follow', false], 'follow' => ['follow', false], 'tail' => ['tail', true], 'n' => ['tail', true], 't' => ['timestamps', false], 'timestamps' => ['timestamps', false], 'since' => ['since', true], 'details' => ['details', false]], 'logs');
        $name = $args->positional[0] ?? throw new UsageError("\"docker logs\" requires exactly 1 argument.\nSee 'docker logs --help'.", 1);
        $container = $this->findContainer($name);
        $lines = $container->logs;
        $tail = $args->get('tail');
        if ($tail !== null && $tail !== 'all') {
            $lines = \array_slice($lines, -(int) $tail);
        }
        if ($args->has('timestamps')) {
            $lines = array_map(static fn ($l) => gmdate('Y-m-d\TH:i:s.000000000\Z', $container->startedAt ?: time()).' '.$l, $lines);
        }
        $this->out->write($lines === [] ? '' : implode("\n", $lines)."\n");
        if ($args->has('follow')) {
            $this->out->line('💡 (simulateur : -f ne suit pas les journaux en continu ; relancez la commande pour voir les nouvelles lignes)');
        }

        return 0;
    }

    /** @param list<string> $argv */
    private function exec(array $argv): int
    {
        $args = Args::parse($argv, ['i' => ['interactive', false], 'interactive' => ['interactive', false], 't' => ['tty', false], 'tty' => ['tty', false], 'u' => ['user', true], 'user' => ['user', true], 'w' => ['workdir', true], 'workdir' => ['workdir', true], 'e' => ['env', true, true], 'env' => ['env', true, true], 'd' => ['detach', false], 'detach' => ['detach', false], 'privileged' => ['privileged', false]], 'exec', stopAtPositional: true);
        if (\count($args->positional) < 2) {
            throw new UsageError("\"docker exec\" requires at least 2 arguments.\nSee 'docker exec --help'.\n\nUsage:  docker exec [OPTIONS] CONTAINER COMMAND [ARG...]\n\nExecute a command in a running container", 1);
        }
        $name = array_shift($args->positional);
        $container = $this->findContainer($name);
        $env = [];
        foreach ($args->all('env') as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $env[$key] = $value;
        }
        $argv = $args->positional;
        $interactiveShell = \in_array(basename($argv[0]), ['sh', 'bash', 'ash', 'php', 'psql', 'mysql', 'redis-cli', 'mariadb'], true) && \count($argv) === 1 && $args->has('interactive');
        try {
            [$code, $output] = $this->docker->exec($container, $argv, $args->get('user'), $args->get('workdir'), $env);
        } catch (DockerException $e) {
            $this->out->line($e->getMessage());

            return $e->exitCode;
        }
        $this->out->write($output);
        if ($interactiveShell && $code === 0) {
            $this->out->line(sprintf('💡 La console du simulateur n\'est pas un terminal : pas de session interactive. Passez la commande directement, par exemple : docker exec %s %s', $name, basename($argv[0]) === 'psql' ? 'psql -U app -c "\\l"' : 'sh -c "ls -la"'));
        }

        return $code;
    }

    /** @param list<string> $argv */
    private function port(array $argv): int
    {
        $container = $this->findContainer($argv[0] ?? '');
        foreach ($container->ports as $port) {
            if (!isset($argv[1]) || (int) $argv[1] === $port['container']) {
                $this->out->line(sprintf('%d/%s -> %s:%d', $port['container'], $port['protocol'], $port['ip'], $port['host']));
                if ($port['ip'] === '0.0.0.0') {
                    $this->out->line(sprintf('%d/%s -> [::]:%d', $port['container'], $port['protocol'], $port['host']));
                }
            }
        }

        return 0;
    }

    /** @param list<string> $argv */
    private function top(array $argv): int
    {
        $container = $this->findContainer($argv[0] ?? '');
        if (!$container->isRunning()) {
            throw new DockerException(sprintf('container %s is not running', $container->id), 1);
        }
        $command = implode(' ', $container->processOptions['argv'] ?? $container->command);
        $rows = [[$container->user ?? 'root', '12345', '12320', '0', '10:00', '?', '00:00:00', $command]];
        if (\in_array($container->process, [ProcessKind::Apache, ProcessKind::PhpFpm, ProcessKind::Nginx], true)) {
            for ($i = 0; $i < 2; ++$i) {
                $rows[] = [$container->process === ProcessKind::Nginx ? 'nginx' : 'www-data', (string) (12350 + $i), '12345', '0', '10:00', '?', '00:00:00', match ($container->process) {
                    ProcessKind::PhpFpm => 'php-fpm: pool www',
                    ProcessKind::Nginx => 'nginx: worker process',
                    default => 'apache2 -DFOREGROUND',
                }];
            }
        }
        $this->out->write(Format::table(['UID', 'PID', 'PPID', 'C', 'STIME', 'TTY', 'TIME', 'CMD'], $rows));

        return 0;
    }

    /** @param list<string> $argv */
    private function cp(array $argv): int
    {
        if (\count($argv) !== 2) {
            throw new UsageError("\"docker cp\" requires exactly 2 arguments.\nSee 'docker cp --help'.", 1);
        }
        [$from, $to] = $argv;
        if (preg_match('/^([^\/:]+):(.+)$/', $from, $m)) {
            $container = $this->findContainer($m[1]);
            $fs = $this->docker->fs($container);
            $source = Path::normalize($m[2], $container->workdir);
            if (!$fs->exists($source)) {
                throw new DockerException(sprintf('Could not find the file %s in container %s', $m[2], $m[1]), 1);
            }
            $target = $this->docker->hostPath($to);
            if (is_dir($target)) {
                $target .= '/'.basename($source);
            }
            if ($fs->isDir($source)) {
                foreach ($fs->files($source) as $file) {
                    @mkdir(\dirname($target.substr($file, \strlen($source))), 0777, true);
                    file_put_contents($target.substr($file, \strlen($source)), (string) $fs->read($file));
                }
            } else {
                @mkdir(\dirname($target), 0777, true);
                file_put_contents($target, (string) $fs->read($source));
            }
            $this->out->line(sprintf('Successfully copied %s to %s', Format::size($fs->size($source)), $to));

            return 0;
        }
        if (preg_match('/^([^\/:]+):(.+)$/', $to, $m)) {
            $container = $this->findContainer($m[1]);
            $fs = $this->docker->fs($container);
            $source = $this->docker->hostPath($from);
            if (!file_exists($source)) {
                $this->out->line(sprintf('lstat %s: no such file or directory', $source));

                return 1;
            }
            $target = Path::normalize($m[2], $container->workdir);
            if ($fs->isDir($target)) {
                $target .= '/'.basename($source);
            }
            if (is_dir($source)) {
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    $fs->writeFromHost($target.substr($file->getPathname(), \strlen($source)), $file->getPathname());
                }
            } else {
                $fs->writeFromHost($target, $source);
            }
            $this->out->line(sprintf('Successfully copied %s to %s', Format::size((int) @filesize($source)), $to));

            return 0;
        }
        throw new UsageError("must specify at least one container source\nSee 'docker cp --help'.", 1);
    }
}
