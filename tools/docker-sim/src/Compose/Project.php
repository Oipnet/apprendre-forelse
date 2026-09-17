<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Compose;

use Forelse\DockerSim\Engine\ContainerSpec;
use Forelse\DockerSim\Engine\Docker;
use Forelse\DockerSim\Engine\DockerException;
use Forelse\DockerSim\State\Container;
use Forelse\DockerSim\State\Store;

/**
 * docker compose sur un projet : up, down, ps, logs, exec, run, build… Les conteneurs portent les
 * étiquettes de Compose (projet, service, empreinte de configuration) : un service dont la
 * configuration n'a pas changé n'est pas recréé.
 */
final class Project
{
    public string $output = '';
    public int $exitCode = 0;

    /** @param list<string> $profiles profils activés (--profile, COMPOSE_PROFILES) */
    public function __construct(public readonly Docker $docker, public readonly ComposeFile $file, public array $profiles = [])
    {
        foreach ($file->warnings as $warning) {
            $this->out('WARN[0000] '.$warning);
        }
    }

    private function out(string $line): void
    {
        $this->output .= $line."\n";
    }

    public function networkName(string $network): string
    {
        $config = $this->file->networks[$network] ?? [];
        if (isset($config['name'])) {
            return (string) $config['name'];
        }

        return $this->file->name.'_'.$network;
    }

    public function volumeName(string $volume): string
    {
        $config = $this->file->volumes[$volume] ?? [];
        if (isset($config['name'])) {
            return (string) $config['name'];
        }

        return $this->file->name.'_'.$volume;
    }

    public function imageName(string $service): string
    {
        return $this->file->services[$service]['image'] ?? $this->file->name.'-'.$service;
    }

    /** @return list<Container> conteneurs du service (hors conteneurs « run ») */
    public function containers(?string $service = null, bool $includeRun = false): array
    {
        $result = [];
        foreach ($this->docker->store->containers as $container) {
            if ($container->composeProject() !== $this->file->name) {
                continue;
            }
            if ($service !== null && $container->composeService() !== $service) {
                continue;
            }
            if (!$includeRun && ($container->labels['com.docker.compose.oneoff'] ?? 'False') === 'True') {
                continue;
            }
            $result[] = $container;
        }
        usort($result, static fn (Container $a, Container $b) => strcmp($a->name, $b->name));

        return $result;
    }

    public function containerName(string $service): string
    {
        return $this->file->services[$service]['container_name'] ?? $this->file->name.'-'.$service.'-1';
    }

    /** @param list<string> $services */
    private function activeServices(array $services): array
    {
        if ($services !== []) {
            return $this->file->sortedServices($services);
        }
        // Les services avec un profil ne démarrent que si l'un de leurs profils est activé.
        $actifs = $this->profiles;
        $defaults = array_keys(array_filter($this->file->services, static fn ($s) => $s['profiles'] === [] || array_intersect($s['profiles'], $actifs) !== []));

        return $this->file->sortedServices($defaults);
    }

    // --- build ---------------------------------------------------------------------------------

    /** @param list<string> $services */
    public function build(array $services = [], bool $noCache = false): bool
    {
        foreach ($services === [] ? array_keys($this->file->services) : $services as $service) {
            if (!isset($this->file->services[$service])) {
                $this->fail(sprintf('no such service: %s', $service));

                return false;
            }
            if (isset($this->file->services[$service]['build']) && !$this->buildService($service, $noCache)) {
                return false;
            }
        }

        return true;
    }

    private function buildService(string $service, bool $noCache = false): bool
    {
        $config = $this->file->services[$service]['build'];
        $result = $this->docker->build($config['context'], $config['dockerfile'], [$this->imageName($service)], $config['target'], $config['args'], $noCache);
        $lines = explode("\n", rtrim($result->output, "\n"));
        $this->out('[+] Building '.sprintf('%.1fs (%d/%d)%s', $result->seconds(), \count($result->steps), \count($result->steps), $result->success ? ' FINISHED' : ''));
        foreach ($lines as $line) {
            if (str_starts_with($line, '#0 ')) {
                continue;
            }
            $line = preg_replace('/^(#\d+ )\[(?!internal\])/', '$1['.$service.' ', $line) ?? $line;
            $line = preg_replace('/^(#\d+ )\[internal\]/', '$1['.$service.' internal]', $line) ?? $line;
            if (str_starts_with($line, 'ERROR: failed to build: failed to solve: ')) {
                $line = 'failed to solve: '.substr($line, \strlen('ERROR: failed to build: failed to solve: '));
                $this->out('');
                $this->fail('target '.$service.': '.$line);
                continue;
            }
            $this->out($line);
        }
        if ($result->success) {
            $this->out(sprintf(' ✔ %s  Built', $service));
        }
        foreach ($result->notes as $note) {
            $this->docker->store->buildCache['notes'][] = $note;
        }

        return $result->success;
    }

    // --- up --------------------------------------------------------------------------------------

    /** @param list<string> $services */
    public function up(array $services = [], bool $detach = true, bool $build = false, bool $forceRecreate = false, bool $noDeps = false, bool $wait = false, bool $noBuild = false): bool
    {
        try {
            $order = $noDeps && $services !== [] ? $services : $this->activeServices($services);
        } catch (ComposeException $e) {
            $this->fail($e->getMessage());

            return false;
        }
        // Images à construire ou à télécharger.
        $toBuild = [];
        foreach ($order as $service) {
            $config = $this->file->services[$service];
            if (isset($config['build']) && !$noBuild && ($build || $this->docker->store->findImage($this->imageName($service)) === null)) {
                $toBuild[] = $service;
            }
        }
        foreach ($toBuild as $service) {
            if (!$this->buildService($service)) {
                return false;
            }
        }
        $pulls = [];
        foreach ($order as $service) {
            $config = $this->file->services[$service];
            if (!isset($config['build']) && $this->docker->store->findImage((string) $config['image']) === null) {
                try {
                    $this->docker->pull((string) $config['image']);
                    $pulls[] = $service;
                } catch (\Forelse\DockerSim\Catalog\UnknownImageException $e) {
                    $this->out('[+] Running 1/1');
                    $this->out(sprintf(' ✘ %s Error %s', $service, $e->getMessage()));
                    $this->fail(sprintf('Error response from daemon: %s', $e->getMessage()));

                    return false;
                }
            }
        }
        if ($pulls !== []) {
            $this->out(sprintf('[+] Pulling %d/%d', \count($pulls), \count($pulls)));
            foreach ($pulls as $service) {
                $this->out(sprintf(' ✔ %s Pulled', $service));
            }
        }

        $events = [];
        // Réseaux et volumes.
        $networks = [];
        foreach ($order as $service) {
            $serviceNetworks = $this->file->services[$service]['networks'] ?: ['default' => []];
            foreach (array_keys($serviceNetworks) as $network) {
                $networks[$network] = true;
            }
        }
        foreach (array_keys($networks) as $network) {
            $name = $this->networkName($network);
            if (($this->file->networks[$network]['external'] ?? false) === true) {
                if (!isset($this->docker->store->networks[$name])) {
                    $this->fail(sprintf('network %s declared as external, but could not be found', $name));

                    return false;
                }
                continue;
            }
            if (!isset($this->docker->store->networks[$name])) {
                $this->docker->createNetwork($name, ['com.docker.compose.project' => $this->file->name, 'com.docker.compose.network' => $network]);
                $events[] = ['Network', $name, 'Created'];
            }
        }
        foreach ($order as $service) {
            foreach ($this->file->services[$service]['volumes'] as $volume) {
                if ($volume['type'] === 'volume' && $volume['source'] !== '') {
                    $name = $this->volumeName($volume['source']);
                    if (($this->file->volumes[$volume['source']]['external'] ?? false) === true) {
                        if (!isset($this->docker->store->volumes[$name])) {
                            $this->fail(sprintf('external volume "%s" not found', $name));

                            return false;
                        }
                        continue;
                    }
                    if (!isset($this->docker->store->volumes[$name])) {
                        $this->docker->createVolume($name, ['com.docker.compose.project' => $this->file->name, 'com.docker.compose.volume' => $volume['source']]);
                        $events[] = ['Volume', '"'.$name.'"', 'Created'];
                    } elseif (($this->docker->store->volumes[$name]->labels['com.docker.compose.project'] ?? null) === null && empty($this->file->volumes[$volume['source']]['external']) && !isset($warnedVolumes[$name])) {
                        $warnedVolumes[$name] = true;
                        $this->out(sprintf('WARN[0000] volume "%s" already exists but was not created by Docker Compose. Use `external: true` to use an existing volume', $name));
                    }
                }
            }
        }

        $ok = true;
        $started = [];
        foreach ($order as $service) {
            $config = $this->file->services[$service];
            // Conditions de depends_on.
            foreach ($config['depends_on'] as $dependency => $options) {
                if ($noDeps) {
                    break;
                }
                $container = $this->containers($dependency)[0] ?? null;
                if ($container === null) {
                    continue;
                }
                $condition = $options['condition'];
                if ($condition === 'service_healthy') {
                    if ($container->healthcheck === null || ($container->healthcheck['test'][0] ?? '') === 'NONE') {
                        $events[] = ['Container', $this->containerName($service), 'Error'];
                        $this->flushEvents($events);
                        $this->fail(sprintf('dependency failed to start: container %s has no healthcheck configured', $container->name));

                        return false;
                    }
                    if ($container->health !== 'healthy') {
                        $events[] = ['Container', $container->name, 'Error'];
                        $this->flushEvents($events);
                        $this->fail(sprintf('dependency failed to start: container %s is unhealthy', $container->name));

                        return false;
                    }
                    $this->replaceEvent($events, $container->name, 'Healthy');
                }
                if ($condition === 'service_completed_successfully' && ($container->isRunning() || $container->exitCode !== 0)) {
                    $this->flushEvents($events);
                    $this->fail(sprintf('service "%s" didn\'t complete successfully: exit %d', $dependency, $container->exitCode));

                    return false;
                }
            }
            try {
                [$container, $action] = $this->ensureContainer($service, $forceRecreate || \in_array($service, $toBuild, true));
                $wasRunning = $container->isRunning();
                if (!$wasRunning) {
                    $this->docker->start($container);
                    $action = $action === 'Recreated' ? 'Recreated' : 'Started';
                } elseif ($action === 'Running') {
                    $action = 'Running';
                }
                $events[] = ['Container', $container->name, $action];
                $started[] = $container;
            } catch (DockerException $e) {
                $events[] = ['Container', $this->containerName($service), 'Error'];
                $this->flushEvents($events);
                $this->fail('Error response from daemon: '.$e->getMessage());

                return false;
            }
        }
        $this->flushEvents($events);
        foreach ($started as $container) {
            if ($container->status !== Container::RUNNING && $detach) {
                // En arrière-plan, Compose ne signale pas l'arrêt d'un conteneur : docker compose ps le montrera.
                continue;
            }
        }
        if ($wait) {
            foreach ($started as $container) {
                $this->docker->refreshHealth($container);
                if ($container->health === 'healthy') {
                    $this->output = preg_replace('/^( ✔ Container '.preg_quote($container->name, '/').'\s+)Started   /m', '$1Healthy   ', $this->output) ?? $this->output;
                }
                if (!$container->isRunning() || $container->health === 'unhealthy') {
                    $this->fail(sprintf('container %s %s', $container->name, $container->health === 'unhealthy' ? 'is unhealthy' : 'exited ('.$container->exitCode.')'));

                    return false;
                }
            }
        }
        if (!$detach) {
            $this->out('Attaching to '.implode(', ', array_map(fn (Container $c) => $this->shortName($c), $started)));
            $width = max(array_map(fn (Container $c) => \strlen($this->shortName($c)), $started) ?: [0]);
            foreach ($started as $container) {
                foreach ($container->logs as $line) {
                    $this->out(str_pad($this->shortName($container), $width).'  | '.$line);
                }
                if (!$container->isRunning()) {
                    $this->out(sprintf('%s exited with code %d', $this->shortName($container), $container->exitCode));
                }
            }
        }
        $this->docker->save();

        return $ok;
    }

    /** @param list<array{0:string,1:string,2:string}> $events */
    private function replaceEvent(array &$events, string $name, string $state): void
    {
        foreach ($events as &$event) {
            if ($event[1] === $name) {
                $event[2] = $state;
            }
        }
    }

    /** @param list<array{0:string,1:string,2:string}> $events */
    private function flushEvents(array &$events): void
    {
        if ($events === []) {
            return;
        }
        $this->out(sprintf('[+] Running %d/%d', \count(array_filter($events, static fn ($e) => $e[2] !== 'Error')), \count($events)));
        $width = max(array_map(static fn ($e) => \strlen($e[0].' '.$e[1]), $events));
        foreach ($events as [$type, $name, $state]) {
            $this->out(sprintf(' %s %s  %s', $state === 'Error' ? '✘' : '✔', str_pad($type.' '.$name, $width), str_pad($state, 10)).sprintf('%.1fs', $state === 'Healthy' ? 5.6 : 0.4));
        }
        $events = [];
    }

    public function shortName(Container $container): string
    {
        $service = $container->composeService();
        if ($service !== null && $container->name === $this->file->name.'-'.$service.'-'.($container->labels['com.docker.compose.container-number'] ?? '1')) {
            return $service.'-'.($container->labels['com.docker.compose.container-number'] ?? '1');
        }

        return $container->name;
    }

    /** @return array{0: Container, 1: string} */
    private function ensureContainer(string $service, bool $recreate): array
    {
        $spec = $this->spec($service);
        $hash = $this->configHash($service, $spec);
        $existing = $this->containers($service)[0] ?? null;
        if ($existing !== null) {
            $image = $this->docker->store->findImage($spec->image);
            $changed = ($existing->labels['com.docker.compose.config-hash'] ?? '') !== $hash || ($image !== null && $image->id !== $existing->imageId);
            if (!$changed && !$recreate) {
                return [$existing, $existing->isRunning() ? 'Running' : 'Started'];
            }
            $this->docker->stop($existing);
            $this->docker->remove($existing, true);
            $spec->labels['com.docker.compose.config-hash'] = $hash;

            return [$this->docker->create($spec), 'Recreated'];
        }
        $spec->labels['com.docker.compose.config-hash'] = $hash;

        return [$this->docker->create($spec), 'Created'];
    }

    public function spec(string $service, bool $oneOff = false): ContainerSpec
    {
        $config = $this->file->services[$service];
        $spec = new ContainerSpec($this->imageName($service));
        $spec->name = $oneOff ? sprintf('%s-%s-run-%s', $this->file->name, $service, substr(bin2hex(random_bytes(6)), 0, 12)) : $this->containerName($service);
        $spec->command = $config['command'] ?? null;
        $spec->entrypoint = \array_key_exists('entrypoint', $config) ? ($config['entrypoint'] ?? []) : null;
        $spec->env = $config['environment'];
        foreach ($config['ports'] as $port) {
            if ($oneOff) {
                break;
            }
            $spec->ports[] = ['host' => $port['host'] ?? (32768 + crc32($service.$port['container']) % 20000), 'container' => $port['container'], 'protocol' => $port['protocol'], 'ip' => $port['ip']];
        }
        foreach ($config['volumes'] as $volume) {
            $spec->mounts[] = ['type' => $volume['type'], 'source' => $volume['type'] === 'volume' && $volume['source'] !== '' ? $this->volumeName($volume['source']) : $volume['source'], 'target' => $volume['target'], 'readOnly' => $volume['readOnly']];
        }
        $networks = $config['networks'] ?: ['default' => []];
        foreach ($networks as $network => $aliases) {
            $spec->networks[$this->networkName($network)] = array_values(array_unique([$service, ...$aliases]));
        }
        $spec->workdir = $config['working_dir'];
        $spec->user = $config['user'];
        $spec->restart = $oneOff ? 'no' : $config['restart'];
        $spec->healthcheck = $config['healthcheck'] ?? null;
        $spec->hostname = $config['hostname'];
        $spec->tty = $config['tty'] || $config['stdin_open'];
        $spec->labels = $config['labels'] + [
            'com.docker.compose.project' => $this->file->name,
            'com.docker.compose.service' => $service,
            'com.docker.compose.container-number' => '1',
            'com.docker.compose.oneoff' => $oneOff ? 'True' : 'False',
            'com.docker.compose.project.config_files' => $this->file->path,
            'com.docker.compose.project.working_dir' => \dirname($this->file->path),
        ];

        return $spec;
    }

    private function configHash(string $service, ContainerSpec $spec): string
    {
        $config = $this->file->services[$service];
        unset($config['depends_on'], $config['profiles']);

        return hash('sha256', json_encode($config));
    }

    // --- down, stop, start -------------------------------------------------------------------

    public function down(bool $volumes = false, bool $removeOrphans = false, ?string $rmi = null): bool
    {
        $events = [];
        // L'inverse de l'ordre de démarrage : ceux qui dépendent des autres s'arrêtent les premiers.
        $sorted = $this->file->sortedServices(array_keys($this->file->services));
        $rank = array_flip(array_reverse($sorted));
        $all = $this->containers(null, true);
        usort($all, static fn (Container $a, Container $b) => [$rank[$a->composeService()] ?? -1, $a->name] <=> [$rank[$b->composeService()] ?? -1, $b->name]);
        foreach ($all as $container) {
            if ($container->isRunning()) {
                $this->docker->stop($container);
                $events[] = ['Container', $container->name, 'Stopped'];
            }
        }
        foreach ($all as $container) {
            $service = $container->composeService();
            if ($service !== null && !isset($this->file->services[$service]) && !$removeOrphans) {
                $this->out(sprintf('WARN[0000] Found orphan containers ([%s]) for this project. If you removed or renamed this service in your compose file, you can run this command with the --remove-orphans flag to clean it up.', $container->name));
                continue;
            }
            $this->docker->remove($container, true, $volumes);
            $events = array_values(array_filter($events, static fn ($e) => $e[1] !== $container->name));
            $events[] = ['Container', $container->name, 'Removed'];
        }
        foreach ($this->docker->store->volumes as $name => $volume) {
            if ($volumes && ($volume->labels['com.docker.compose.project'] ?? null) === $this->file->name) {
                $this->docker->store->removeVolume($name);
                $events[] = ['Volume', $name, 'Removed'];
            }
        }
        foreach ($this->docker->store->networks as $name => $network) {
            if (($network->labels['com.docker.compose.project'] ?? null) === $this->file->name) {
                unset($this->docker->store->networks[$name]);
                $events[] = ['Network', $name, 'Removed'];
            }
        }
        if ($rmi !== null) {
            foreach (array_keys($this->file->services) as $service) {
                $image = $this->docker->store->findImage($this->imageName($service));
                if ($image !== null && ($rmi === 'all' || !isset($this->file->services[$service]['image']))) {
                    $this->docker->store->removeImage($image);
                    $events[] = ['Image', $this->imageName($service), 'Removed'];
                }
            }
        }
        if ($events !== []) {
            $this->flushEvents($events);
        }
        $this->docker->save();

        return true;
    }

    /** @param list<string> $services */
    public function stop(array $services = []): bool
    {
        $events = [];
        foreach ($this->containers() as $container) {
            if (($services === [] || \in_array($container->composeService(), $services, true)) && $container->isRunning()) {
                $this->docker->stop($container);
                $events[] = ['Container', $container->name, 'Stopped'];
            }
        }
        $this->flushEvents($events);

        return true;
    }

    /** @param list<string> $services */
    public function start(array $services = []): bool
    {
        $events = [];
        foreach ($this->file->sortedServices($services === [] ? null : $services) as $service) {
            foreach ($this->containers($service) as $container) {
                if (!$container->isRunning()) {
                    try {
                        $this->docker->start($container);
                        $events[] = ['Container', $container->name, 'Started'];
                    } catch (DockerException $e) {
                        $this->flushEvents($events);
                        $this->fail('Error response from daemon: '.$e->getMessage());

                        return false;
                    }
                }
            }
        }
        if ($events === [] && $this->containers() === []) {
            $this->fail('service "'.($services[0] ?? array_key_first($this->file->services)).'" has no container to start');

            return false;
        }
        $this->flushEvents($events);

        return true;
    }

    /** @param list<string> $services */
    public function restart(array $services = []): bool
    {
        $events = [];
        foreach ($this->containers() as $container) {
            if ($services === [] || \in_array($container->composeService(), $services, true)) {
                try {
                    $this->docker->restart($container);
                    $events[] = ['Container', $container->name, 'Started'];
                } catch (DockerException $e) {
                    $this->fail('Error response from daemon: '.$e->getMessage());

                    return false;
                }
            }
        }
        $this->flushEvents($events);

        return true;
    }

    // --- exec, run, logs -------------------------------------------------------------------

    /**
     * @param list<string>          $argv
     * @param array<string,string>  $env
     */
    public function exec(string $service, array $argv, ?string $user = null, ?string $workdir = null, array $env = []): int
    {
        if (!isset($this->file->services[$service])) {
            return $this->fail(sprintf('service "%s" is not running', $service), 1);
        }
        $container = $this->containers($service)[0] ?? null;
        if ($container === null || !$container->isRunning()) {
            return $this->fail(sprintf('service "%s" is not running', $service), 1);
        }
        try {
            [$code, $output] = $this->docker->exec($container, $argv, $user, $workdir, $env);
        } catch (DockerException $e) {
            return $this->fail($e->getMessage(), $e->exitCode);
        }
        $this->output .= $output;
        $this->exitCode = $code;

        return $code;
    }

    /**
     * @param list<string>|null     $argv
     * @param array<string,string>  $env
     */
    public function run(string $service, ?array $argv, bool $remove = false, bool $noDeps = false, ?string $entrypoint = null, array $env = [], ?string $user = null, bool $publish = false): int
    {
        if (!isset($this->file->services[$service])) {
            return $this->fail(sprintf('no such service: %s', $service), 1);
        }
        $dependencies = array_keys($this->file->services[$service]['depends_on']);
        if (!$noDeps && $dependencies !== []) {
            $saved = $this->output;
            $this->output = '';
            if (!$this->up($dependencies, true)) {
                $this->output = $saved.$this->output;

                return $this->exitCode ?: 1;
            }
            $this->output = $saved.$this->output;
        } else {
            // Au moins le réseau du projet.
            foreach (array_keys($this->file->services[$service]['networks'] ?: ['default' => []]) as $network) {
                if (!isset($this->docker->store->networks[$this->networkName($network)])) {
                    $this->docker->createNetwork($this->networkName($network), ['com.docker.compose.project' => $this->file->name, 'com.docker.compose.network' => $network]);
                }
            }
        }
        if (isset($this->file->services[$service]['build']) && $this->docker->store->findImage($this->imageName($service)) === null && !$this->buildService($service)) {
            return $this->exitCode ?: 1;
        }
        foreach ($this->file->services[$service]['volumes'] as $volume) {
            if ($volume['type'] === 'volume' && $volume['source'] !== '' && !isset($this->docker->store->volumes[$this->volumeName($volume['source'])])) {
                $this->docker->createVolume($this->volumeName($volume['source']), ['com.docker.compose.project' => $this->file->name]);
            }
        }
        $spec = $this->spec($service, true);
        if ($argv !== null && $argv !== []) {
            $spec->command = $argv;
        }
        if ($entrypoint !== null) {
            $spec->entrypoint = ComposeFile::shellSplit($entrypoint);
        }
        $spec->env = $env + $spec->env;
        if ($user !== null) {
            $spec->user = $user;
        }
        $spec->restart = 'no';
        try {
            $container = $this->docker->create($spec);
            $this->docker->start($container);
        } catch (DockerException $e) {
            return $this->fail('Error response from daemon: '.$e->getMessage(), $e->exitCode);
        }
        $this->output .= implode("\n", $container->logs).($container->logs !== [] ? "\n" : '');
        if ($container->isRunning()) {
            $this->out(sprintf('(simulateur : le conteneur %s continue de tourner en arrière-plan, la console ne peut pas s\'y attacher)', $container->name));
            $this->exitCode = 0;

            return 0;
        }
        $code = $container->exitCode;
        if ($remove) {
            $this->docker->remove($container, true, true);
        }
        $this->exitCode = $code;

        return $code;
    }

    /** @param list<string> $services */
    public function logs(array $services = [], ?int $tail = null, bool $noPrefix = false): string
    {
        $containers = array_filter($this->containers(), static fn (Container $c) => $services === [] || \in_array($c->composeService(), $services, true));
        $width = max(array_map(fn (Container $c) => \strlen($this->shortName($c)), $containers) ?: [0]);
        $text = '';
        foreach ($containers as $container) {
            $lines = $tail !== null ? \array_slice($container->logs, -$tail) : $container->logs;
            foreach ($lines as $line) {
                $text .= ($noPrefix ? '' : str_pad($this->shortName($container), $width).'  | ').$line."\n";
            }
        }

        return $text;
    }

    public function fail(string $message, int $code = 1): int
    {
        $this->out($message);
        $this->exitCode = $code;

        return $code;
    }
}
