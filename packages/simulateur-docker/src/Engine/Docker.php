<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Engine;

use Forelse\DockerSim\Build\Builder;
use Forelse\DockerSim\Build\BuildResult;
use Forelse\DockerSim\Catalog\Catalog;
use Forelse\DockerSim\Fs\DiskFs;
use Forelse\DockerSim\Fs\Path;
use Forelse\DockerSim\Http\ApacheServer;
use Forelse\DockerSim\Http\ErrorPages;
use Forelse\DockerSim\Http\HttpRequest;
use Forelse\DockerSim\Http\HttpResponse;
use Forelse\DockerSim\Http\NginxServer;
use Forelse\DockerSim\Http\PhpBuiltinServer;
use Forelse\DockerSim\Http\ServerContext;
use Forelse\DockerSim\Http\StaticFiles;
use Forelse\DockerSim\Runtime\Materializer;
use Forelse\DockerSim\Runtime\PhpExecutor;
use Forelse\DockerSim\Runtime\ProcessManager;
use Forelse\DockerSim\Shell\Facts;
use Forelse\DockerSim\Shell\Interpreter;
use Forelse\DockerSim\Shell\Machine;
use Forelse\DockerSim\Shell\Network as ShellNetwork;
use Forelse\DockerSim\State\Container;
use Forelse\DockerSim\State\Image;
use Forelse\DockerSim\State\Network;
use Forelse\DockerSim\State\Store;
use Forelse\DockerSim\State\Volume;

/**
 * Le « démon » Docker simulé : images, conteneurs, réseaux, volumes, et le trafic HTTP de l'hôte vers
 * les ports publiés. La ligne de commande (Cli), compose et les tests des exercices passent par lui.
 */
final class Docker implements ServerContext
{
    public readonly Store $store;
    public readonly Catalog $catalog;
    public readonly Interpreter $shell;
    public readonly Materializer $materializer;
    private readonly PhpExecutor $php;
    private readonly ProcessManager $processes;

    public function __construct(string $stateDirectory, public readonly string $projectDirectory)
    {
        $this->store = new Store($stateDirectory);
        $this->catalog = new Catalog();
        $this->shell = Interpreter::create();
        $this->materializer = new Materializer($this->store);
        $this->php = new PhpExecutor($this->materializer);
        $this->processes = new ProcessManager($this);
    }

    public function save(): void
    {
        $this->store->save();
    }

    // --- Images -----------------------------------------------------------------------------

    /** @return array{0: Image, 1: string} l'image et la sortie de docker pull */
    public function pull(string $reference): array
    {
        $base = $this->catalog->resolve($reference);
        $existing = $this->store->findImage($base->reference());
        [$repository, $tag] = Catalog::split($reference);
        $canonical = Catalog::canonical($repository, $tag);
        if ($existing !== null) {
            return [$existing, sprintf("%s: Pulling from %s\nDigest: %s\nStatus: Image is up to date for %s\n%s\n", $tag, str_contains($repository, '/') ? $repository : 'library/'.$repository, $base->digest(), $canonical, $canonical)];
        }
        $image = ImageFactory::fromBase($base);
        $this->store->images[$image->id] = $image;
        $output = sprintf("%s: Pulling from %s\n", $tag, str_contains($repository, '/') ? $repository : 'library/'.$repository);
        foreach (\array_slice($image->layers, 0, 6) as $layer) {
            if (!$layer->empty) {
                $output .= substr(hash('sha256', $layer->id), 0, 12).": Pull complete\n";
            }
        }
        $output .= sprintf("Digest: %s\nStatus: Downloaded newer image for %s\n%s\n", $base->digest(), $canonical, $canonical);
        $this->save();

        return [$image, $output];
    }

    /** Image locale, téléchargée si besoin (« Unable to find image … locally »). */
    public function image(string $reference, string &$output = ''): Image
    {
        $local = $this->store->findImage($reference);
        if ($local !== null) {
            return $local;
        }
        try {
            [$repository, $tag] = Catalog::split($reference);
            [$image, $pullOutput] = $this->pull($reference);
            $output .= sprintf("Unable to find image '%s' locally\n", str_contains($reference, ':') || str_contains($reference, '@') ? $reference : $reference.':latest').$pullOutput;

            return $image;
        } catch (\Forelse\DockerSim\Catalog\UnknownImageException $e) {
            $output .= sprintf("Unable to find image '%s' locally\n", str_contains($reference, ':') ? $reference : $reference.':latest');

            throw new DockerException($e->getMessage(), 125);
        }
    }

    /**
     * @param list<string>         $tags
     * @param array<string,string> $buildArgs
     */
    public function build(string $context, ?string $dockerfile = null, array $tags = [], ?string $target = null, array $buildArgs = [], bool $noCache = false): BuildResult
    {
        $context = $this->hostPath($context);
        $label = $dockerfile ?? 'Dockerfile';
        $dockerfile = $dockerfile !== null ? (str_starts_with($dockerfile, '/') ? $dockerfile : rtrim($context, '/').'/'.$dockerfile) : null;

        return (new Builder($this->store, $this->catalog, $this->shell))->build($context, $dockerfile, $tags, $target, $buildArgs, $noCache, $label);
    }

    public function hostPath(string $path): string
    {
        return str_starts_with($path, '/') ? Path::normalize($path) : Path::normalize($path, $this->projectDirectory);
    }

    // --- Conteneurs -------------------------------------------------------------------------

    public function create(ContainerSpec $spec, string &$output = ''): Container
    {
        $image = $this->image($spec->image, $output);
        if ($spec->name !== null) {
            if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $spec->name)) {
                throw new DockerException(sprintf('Invalid container name (%s), only [a-zA-Z0-9][a-zA-Z0-9_.-] are allowed', $spec->name));
            }
            $existing = $this->store->findContainer($spec->name);
            if ($existing !== null && $existing->name === $spec->name) {
                throw new DockerException(sprintf('Conflict. The container name "/%s" is already in use by container "%s". You have to remove (or rename) that container to be able to reuse that name.', $spec->name, $existing->id));
            }
        }
        $config = $image->config;
        $entrypoint = $spec->entrypoint ?? ($config->entrypoint ?? []);
        $cmd = $spec->command ?? ($spec->entrypoint !== null ? [] : ($config->cmd ?? []));
        $id = Store::id();
        $name = $spec->name ?? Names::generate(fn (string $n) => $this->store->findContainer($n) !== null);
        $networks = $spec->networks !== [] ? $spec->networks : ['bridge' => []];
        foreach (array_keys($networks) as $network) {
            if (!isset($this->store->networks[$network])) {
                throw new DockerException(sprintf('network %s not found', $network), 125);
            }
        }
        $mounts = [];
        foreach ($spec->mounts as $mount) {
            if ($mount['type'] === 'volume') {
                $volume = $mount['source'] !== '' ? $mount['source'] : hash('sha256', random_bytes(16));
                if (!isset($this->store->volumes[$volume])) {
                    $this->store->volumes[$volume] = new Volume($volume, [], time(), $mount['source'] === '');
                }
                $mount['source'] = $volume;
            } else {
                $mount['source'] = $this->hostPath($mount['source']);
            }
            $mounts[] = $mount;
        }
        // VOLUME de l'image : un volume anonyme, sauf si un montage couvre déjà ce chemin.
        foreach ($config->volumes as $path) {
            foreach ($mounts as $mount) {
                if (Path::normalize($mount['target']) === Path::normalize($path)) {
                    continue 2;
                }
            }
            $volume = hash('sha256', random_bytes(16));
            $this->store->volumes[$volume] = new Volume($volume, [], time(), true);
            $mounts[] = ['type' => 'volume', 'source' => $volume, 'target' => $path, 'readOnly' => false];
        }
        $ports = $spec->ports;
        if ($spec->publishAll) {
            foreach ($config->exposed as $exposed) {
                $ports[] = ['host' => 32768 + \count($ports) + random_int(0, 2000), 'container' => (int) $exposed, 'protocol' => 'tcp', 'ip' => '0.0.0.0'];
            }
        }
        $ips = [];
        foreach (array_keys($networks) as $network) {
            if ($network !== 'host' && $network !== 'none') {
                $ips[$network] = $this->store->nextIp($network);
            }
        }
        $container = new Container(
            id: $id,
            name: $name,
            imageId: $image->id,
            imageRef: Store::normalizeTag($spec->image) === $spec->image || str_contains($spec->image, ':') ? $spec->image : $spec->image,
            command: [...$entrypoint, ...$cmd],
            env: $config->env + [] ,
            workdir: $spec->workdir !== null ? Path::normalize($spec->workdir, $config->workdir) : $config->workdir,
            user: $spec->user ?? $config->user,
            ports: $ports,
            mounts: $mounts,
            networks: $networks,
            labels: $config->labels + $spec->labels,
            restart: $spec->restart,
            healthcheck: $spec->healthcheck ?? $config->healthcheck,
            autoRemove: $spec->autoRemove,
            createdAt: time(),
            hostname: $spec->hostname ?? substr($id, 0, 12),
            ips: $ips,
            tty: $spec->tty,
        );
        foreach ($spec->env as $key => $value) {
            $container->env[$key] = $value;
        }
        $container->env['HOSTNAME'] = $container->hostname;
        $this->store->containers[$id] = $container;
        $this->materializer->ensure($container, $image);
        $this->save();

        return $container;
    }

    public function start(Container $container): void
    {
        if ($container->isRunning()) {
            return;
        }
        foreach ($container->ports as $port) {
            foreach ($this->store->containers as $other) {
                if ($other->id === $container->id || !$other->isRunning()) {
                    continue;
                }
                foreach ($other->ports as $otherPort) {
                    if ($otherPort['host'] === $port['host']) {
                        throw new DockerException(sprintf('failed to set up container networking: driver failed programming external connectivity on endpoint %s (%s): Bind for %s:%d failed: port is already allocated', $container->name, $container->id, $port['ip'], $port['host']));
                    }
                }
            }
        }
        $image = $this->store->images[$container->imageId] ?? null;
        if ($image === null) {
            throw new DockerException(sprintf('No such image: %s', $container->imageRef));
        }
        $this->materializer->ensure($container, $image);
        try {
            $this->processes->start($container, $image);
        } catch (DockerException $e) {
            $container->status = Container::CREATED;
            $container->exitCode = $e->exitCode;
            $container->error = $e->getMessage();
            $this->save();

            throw $e;
        }
        if ($container->autoRemove && !$container->isRunning() && $container->status !== Container::RESTARTING) {
            $this->remove($container, true);

            return;
        }
        $this->save();
    }

    public function stop(Container $container): void
    {
        if ($container->status === Container::RUNNING || $container->status === Container::RESTARTING) {
            $container->status = Container::EXITED;
            // Apache et nginx s'arrêtent proprement sur leur signal ; PID 1 sans gestion de SIGTERM est tué au bout de 10 s (137).
            $container->exitCode = \in_array($container->process, ['apache', 'nginx', 'php-fpm', 'postgres', 'mysql', 'mariadb', 'redis', 'php-server'], true) ? 0 : 137;
            $container->finishedAt = time();
            $container->listening = [];
            $container->process = null;
            $container->health = null;
            if ($container->autoRemove) {
                $this->remove($container, true);

                return;
            }
        }
        $this->save();
    }

    public function remove(Container $container, bool $force = false, bool $volumes = false): void
    {
        if ($container->isRunning() && !$force) {
            throw new DockerException(sprintf('cannot remove container "%s": container is running: stop the container before removing or force remove', $container->name), 1);
        }
        $this->store->removeContainer($container);
        foreach ($container->mounts as $mount) {
            if ($mount['type'] === 'volume' && ($volumes || $container->autoRemove) && ($this->store->volumes[$mount['source']]->anonymous ?? false)) {
                $this->store->removeVolume($mount['source']);
            }
        }
        $this->save();
    }

    /**
     * docker exec : une commande dans un conteneur en cours d'exécution.
     *
     * @param list<string>          $argv
     * @param array<string,string>  $env
     *
     * @return array{0: int, 1: string}
     */
    public function exec(Container $container, array $argv, ?string $user = null, ?string $workdir = null, array $env = []): array
    {
        if (!$container->isRunning()) {
            throw new DockerException(sprintf('container %s is not running', $container->id), 1);
        }
        $machine = $this->machine($container);
        $machine->env = $env + $machine->env;
        if ($user !== null) {
            $name = explode(':', $user)[0];
            if (!ctype_digit($name) && !isset($machine->facts->users[$name])) {
                throw new DockerException(sprintf('unable to find user %s: no matching entries in passwd file', $name), 126, false);
            }
            $machine->user = $user;
        }
        if ($workdir !== null) {
            $machine->cwd = Path::normalize($workdir, $container->workdir);
        }
        $name = $argv[0] ?? '';
        if (!str_contains($name, '/') && !$machine->facts->hasBinary($name) && $this->findInPath($machine, $name) === null) {
            throw new DockerException(sprintf('OCI runtime exec failed: exec failed: unable to start container process: exec: "%s": executable file not found in $PATH: unknown', $name), 126, false);
        }
        $machine->output = '';
        $code = $this->shell->runArgv($argv, $machine);
        if (\in_array('nginx-reload', $machine->signals, true)) {
            $this->processes->reloadNginx($container, $machine);
        }
        foreach ($machine->signals as $signal) {
            if (str_starts_with($signal, 'kill:')) {
                $this->processes->signal($container, $machine, substr($signal, 5));
            }
        }
        $this->saveFacts($container, $machine->facts);
        $this->save();

        return [$code, $machine->output];
    }

    /** Rejoue le healthcheck d'un conteneur qui tourne, au moment où l'on regarde son état. */
    public function refreshHealth(Container $container): void
    {
        $test = $container->healthcheck['test'] ?? $this->store->images[$container->imageId]->config->healthcheck['test'] ?? null;
        if (!$container->isRunning() || $test === null) {
            return;
        }
        $before = $container->health;
        $this->processes->health($container);
        if ($before !== $container->health) {
            $this->save();
        }
    }

    public function restart(Container $container): void
    {
        $this->stop($container);
        $container->logs[] = '';
        $this->start($container);
    }

    // --- Réseaux et volumes -----------------------------------------------------------------

    /** @param array<string,string> $labels */
    public function createNetwork(string $name, array $labels = []): Network
    {
        if (isset($this->store->networks[$name])) {
            throw new DockerException(sprintf('network with name %s already exists', $name), 1);
        }
        // Le premier sous-réseau libre, comme Docker : un réseau supprimé rend le sien.
        $used = array_map(static fn (Network $n) => $n->subnet, $this->store->networks);
        $second = 18;
        while (\in_array(sprintf('172.%d.0.0/16', $second), $used, true) && $second < 31) {
            ++$second;
        }
        $network = new Network(Store::id(), $name, 'bridge', sprintf('172.%d.0.0/16', $second), $labels, time());
        $this->store->networks[$name] = $network;
        $this->save();

        return $network;
    }

    public function removeNetwork(string $name): void
    {
        $network = $this->store->networks[$name] ?? null;
        if ($network === null) {
            throw new DockerException(sprintf('network %s not found', $name), 1);
        }
        if ($network->builtin) {
            throw new DockerException(sprintf('%s is a pre-defined network and cannot be removed', $name), 1);
        }
        foreach ($this->store->containers as $container) {
            if (isset($container->networks[$name]) && $container->isRunning()) {
                throw new DockerException(sprintf('error while removing network: network %s id %s has active endpoints', $name, $network->id), 1);
            }
        }
        unset($this->store->networks[$name]);
        $this->save();
    }

    /** @param list<string> $aliases */
    public function connect(Container $container, string $network, array $aliases = []): void
    {
        if (!isset($this->store->networks[$network])) {
            throw new DockerException(sprintf('network %s not found', $network), 1);
        }
        $container->networks[$network] = $aliases;
        $container->ips[$network] ??= $this->store->nextIp($network);
        $this->save();
    }

    /** @param array<string,string> $labels */
    public function createVolume(string $name, array $labels = []): Volume
    {
        $this->store->volumes[$name] ??= new Volume($name, $labels, time());
        @mkdir($this->store->volumePath($name), 0777, true);
        $this->save();

        return $this->store->volumes[$name];
    }

    public function removeVolume(string $name, bool $force = false): void
    {
        if (!isset($this->store->volumes[$name])) {
            // --force ne se plaint pas d'un volume déjà absent ; sans lui, c'est une erreur.
            if ($force) {
                return;
            }

            throw new DockerException(sprintf('get %s: no such volume', $name), 1);
        }
        foreach ($this->store->containers as $container) {
            foreach ($container->mounts as $mount) {
                // --force ne délie pas un volume monté : Docker refuse aussi.
                if ($mount['type'] === 'volume' && $mount['source'] === $name) {
                    throw new DockerException(sprintf('remove %s: volume is in use - [%s]', $name, $container->id), 1);
                }
            }
        }
        $this->store->removeVolume($name);
        $this->save();
    }

    // --- HTTP ---------------------------------------------------------------------------------

    /** Une requête depuis l'hôte (le navigateur, curl sur la machine) : http://localhost:8080/chemin. */
    public function http(string $method, string $url, array $headers = [], string $body = ''): HttpResponse
    {
        if (!preg_match('#^[a-z]+://#', $url)) {
            $url = 'http://'.ltrim($url, '/');
        }
        $parts = parse_url($url) ?: [];
        $host = (string) ($parts['host'] ?? 'localhost');
        $port = (int) ($parts['port'] ?? 80);
        $uri = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
        if (!\in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '[::1]'], true)) {
            return HttpResponse::failure('unresolved', sprintf('« %s » : seul localhost désigne la machine hôte (les noms des conteneurs ne sont connus qu\'à l\'intérieur de leur réseau).', $host));
        }
        foreach ($this->store->containers as $container) {
            if (!$container->isRunning()) {
                continue;
            }
            foreach ($container->ports as $published) {
                if ($published['host'] !== $port) {
                    continue;
                }
                $request = HttpRequest::fromUrl($method, $uri, ['host' => $host.($port !== 80 ? ':'.$port : '')] + $headers, $body, '172.17.0.1', $host, $port);
                $containerPort = $published['container'];
                if (!\in_array($containerPort, $container->listening, true)) {
                    return HttpResponse::failure('reset', sprintf('Le port %d de l\'hôte mène au port %d du conteneur %s, mais rien n\'y écoute (le processus écoute sur : %s).', $port, $containerPort, $container->name, $container->listening === [] ? 'aucun port' : implode(', ', $container->listening)), [$container->name]);
                }
                $address = $container->listenAddresses[$containerPort] ?? '0.0.0.0';
                if (\in_array($address, ['127.0.0.1', 'localhost'], true)) {
                    return HttpResponse::failure('empty', sprintf('Le serveur du conteneur %s écoute sur 127.0.0.1:%d : il n\'accepte que les connexions venues de l\'intérieur du conteneur. Écoutez sur 0.0.0.0.', $container->name, $containerPort), [$container->name]);
                }
                $response = $this->serve($container, $containerPort, $request);
                $response->container ??= $container->name;
                $this->save();

                return $response;
            }
        }

        return HttpResponse::failure('refused', sprintf('Aucun conteneur en cours d\'exécution ne publie le port %d de l\'hôte.', $port));
    }

    /** Un conteneur répond sur l'un de ses ports (depuis l'hôte ou depuis un autre conteneur). */
    public function serve(Container $container, int $port, HttpRequest $request): HttpResponse
    {
        return match ($container->process) {
            'apache' => (new ApacheServer($this))->handle($container, $request, $port),
            'nginx' => (new NginxServer($this))->handle($container, $request, $port),
            'php-server' => (new PhpBuiltinServer($this))->handle($container, $request, ['docroot' => (string) ($container->processOptions['docroot'] ?? $container->workdir), 'router' => $container->processOptions['router'] ?? null]),
            'static', 'mail', 'frankenphp' => $this->serveStatic($container, $request),
            'php-fpm' => HttpResponse::failure('empty', sprintf('php-fpm (%s) parle FastCGI, pas HTTP : il faut un serveur web (nginx) devant lui.', $container->name)),
            'postgres', 'mysql', 'mariadb', 'redis', 'memcached' => HttpResponse::failure('empty', sprintf('%s (%s) n\'est pas un serveur web.', $container->process, $container->name)),
            default => HttpResponse::failure('refused', sprintf('Rien n\'écoute sur le port %d du conteneur %s.', $port, $container->name)),
        };
    }

    private function serveStatic(Container $container, HttpRequest $request): HttpResponse
    {
        $fs = $this->fs($container);
        $root = rtrim((string) ($container->processOptions['docroot'] ?? '/usr/share/nginx/html'), '/');
        $path = Path::normalize($request->path);
        if ($container->process === 'frankenphp') {
            $script = $fs->isFile($root.$path) && str_ends_with($path, '.php') ? $root.$path : $root.'/index.php';
            if (!$fs->isFile($root.$path) && $fs->isFile($script)) {
                return $this->php->serve($container, $script, $root, $request, '/index.php', ['SERVER_SOFTWARE' => 'FrankenPHP']);
            }
        }
        $file = $fs->isDir($root.$path) ? rtrim($root.$path, '/').'/index.html' : $root.$path;
        if (!$fs->isFile($file)) {
            return HttpResponse::page(404, '404 page not found', 'Caddy');
        }

        return StaticFiles::serve($fs, $file, 'Caddy');
    }

    /** Page affichée dans l'aperçu quand la connexion échoue (comme le ferait le navigateur). */
    public static function browserError(HttpResponse $response, int $port): string
    {
        return ErrorPages::browser((string) $response->error, 'localhost', $port, implode(' ', $response->trace));
    }

    // --- ServerContext ------------------------------------------------------------------------

    public function fs(Container $container): DiskFs
    {
        return $this->materializer->fs($container);
    }

    public function network(Container $container): ShellNetwork
    {
        return new ContainerNetwork($this, $container);
    }

    public function php(): PhpExecutor
    {
        return $this->php;
    }

    public function log(Container $container, string $line): void
    {
        $this->processes->appendLogs($container, $line);
    }

    public function upstream(Container $from, string $host, int $port): array
    {
        $connection = $this->network($from)->connect($host, $port);

        return [$connection['container'], $connection['process'], $connection['status']];
    }

    // --- Utilitaires --------------------------------------------------------------------------

    /** La machine d'exécution d'un conteneur (docker exec, script d'entrée, healthcheck). */
    public function machine(Container $container): Machine
    {
        $image = $this->store->images[$container->imageId] ?? null;
        $facts = $container->facts !== null
            ? Facts::fromImage(new Image('x', [], [], new \Forelse\DockerSim\State\ImageConfig(), '', $image?->kind ?? 'shell', $image?->os ?? 'debian', $container->facts['packages'], $container->facts['phpExtensions'], $container->facts['binaries'], $container->facts['apacheModules'], $image?->phpVersion, null, 0, false, null, $container->facts['users']))
            : ($image !== null ? Facts::fromImage($image) : new Facts('debian', 'shell', null));
        $env = $container->env;
        $env['__SIM_MAIN_PROCESS'] = implode(' ', $container->processOptions['argv'] ?? $container->command);

        return new Machine(
            fs: $this->fs($container),
            facts: $facts,
            env: $env,
            cwd: $container->workdir,
            user: $container->user ?? 'root',
            mode: Machine::EXEC,
            network: $this->network($container),
            php: fn (array $argv, string $cwd, array $env, string $stdin) => $this->php->cli($container, $argv, $cwd, $env, $stdin),
            hostname: $container->hostname,
            hostProject: $this->projectDirectory,
        );
    }

    public function saveFacts(Container $container, Facts $facts): void
    {
        $container->facts = $facts->export();
    }

    public function findInPath(Machine $machine, string $name): ?string
    {
        if (str_contains($name, '/')) {
            return $machine->fs->isFile($machine->path($name)) ? $machine->path($name) : null;
        }
        foreach (explode(':', $machine->env['PATH'] ?? '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin') as $dir) {
            $candidate = rtrim($dir, '/').'/'.$name;
            if ($machine->fs->isFile($candidate) && filesize($machine->fs->real($candidate)) > 0) {
                return $candidate;
            }
        }

        return null;
    }
}
