<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Cli;

use Forelse\DockerSim\Catalog\Catalog;
use Forelse\DockerSim\Catalog\UnknownImageException;
use Forelse\DockerSim\Engine\ContainerSpec;
use Forelse\DockerSim\Engine\Docker;
use Forelse\DockerSim\Engine\DockerException;
use Forelse\DockerSim\Fs\Path;
use Forelse\DockerSim\State\Container;
use Forelse\DockerSim\State\Image;
use Forelse\DockerSim\State\Store;

/** La commande « docker » : analyse des arguments, appel du démon simulé, sortie au format de la vraie CLI. */
final class Application
{
    public const VERSION = '28.4.0';

    public string $output = '';
    public readonly Docker $docker;

    public function __construct(string $stateDirectory, string $projectDirectory)
    {
        $this->docker = new Docker($stateDirectory, $projectDirectory);
    }

    public static function defaultStateDirectory(string $projectDirectory): string
    {
        $configured = getenv('DOCKER_SIM_STATE');

        return $configured !== false && $configured !== '' ? $configured : rtrim(sys_get_temp_dir(), '/').'/docker-sim-'.substr(md5($projectDirectory), 0, 12);
    }

    private function write(string $text): void
    {
        $this->output .= $text;
    }

    private function line(string $text = ''): void
    {
        $this->output .= $text."\n";
    }

    /**
     * Variables posées devant la commande (`APP_ENV=dev docker compose up`) : Compose les lit,
     * et elles l'emportent sur le fichier .env du projet.
     *
     * @var array<string,string>
     */
    public array $environment = [];

    /** @param list<string> $argv arguments sans « docker » */
    public function run(array $argv): int
    {
        $this->environment = [];
        while ($argv !== [] && preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/s', $argv[0], $m)) {
            $this->environment[$m[1]] = $m[2];
            array_shift($argv);
        }
        if (($argv[0] ?? null) === 'docker') {
            array_shift($argv);
        }
        if (($argv[0] ?? null) === 'docker-compose') {
            $argv[0] = 'compose';
        }
        try {
            return $this->dispatch($argv);
        } catch (UsageError $e) {
            $this->line('docker: '.$e->getMessage());

            return $e->exitCode;
        } catch (DockerException $e) {
            $this->line(($e->daemon ? 'Error response from daemon: ' : '').$e->getMessage());

            return $e->exitCode === 125 ? 1 : $e->exitCode;
        } finally {
            $this->docker->save();
        }
    }

    /** @param list<string> $argv */
    private function dispatch(array $argv): int
    {
        $command = array_shift($argv);
        if ($command === null || \in_array($command, ['--help', '-h', 'help'], true)) {
            $this->line(Help::MAIN);

            return 0;
        }
        if ($command === '-v' || $command === '--version') {
            $this->line('Docker version '.self::VERSION.', build 249d679');

            return 0;
        }
        if (\in_array('--help', $argv, true) || \in_array('-h', $argv, true) && $command !== 'run' && $command !== 'exec') {
            $this->line(Help::command($command, $argv));

            return 0;
        }

        return match ($command) {
            'version' => $this->version(),
            'info' => $this->info(),
            'build' => $this->build($argv),
            'buildx', 'builder' => ($argv[0] ?? '') === 'build' ? $this->build(\array_slice($argv, 1)) : $this->unknown('buildx '.($argv[0] ?? '')),
            'images' => $this->images($argv),
            'image' => $this->image($argv),
            'rmi' => $this->rmi($argv),
            'pull' => $this->pull($argv),
            'push' => $this->push($argv),
            'tag' => $this->tag($argv),
            'history' => $this->history($argv),
            'run' => $this->runContainer($argv),
            'create' => $this->runContainer($argv, createOnly: true),
            'start' => $this->start($argv),
            'stop', 'kill' => $this->stop($argv, $command),
            'restart' => $this->restart($argv),
            'rm' => $this->rm($argv),
            'ps' => $this->ps($argv),
            'container' => $this->container($argv),
            'logs' => $this->logs($argv),
            'exec' => $this->exec($argv),
            'inspect' => $this->inspect($argv),
            'port' => $this->port($argv),
            'top' => $this->top($argv),
            'cp' => $this->cp($argv),
            'network' => $this->network($argv),
            'volume' => $this->volume($argv),
            'system' => $this->system($argv),
            'compose' => (new ComposeCommand($this->docker, $this->environment))->run($argv, $this->output),
            'login' => $this->simpleLine('Login Succeeded (simulateur : aucun registre n\'est contacté)'),
            'logout' => $this->simpleLine('Removing login credentials for https://index.docker.io/v1/'),
            'init' => $this->simpleLine("docker init : l'assistant interactif n'est pas disponible dans le simulateur.\nÉcrivez le Dockerfile et le compose.yaml à la main : c'est l'objet du parcours.", 1),
            'search' => $this->search($argv),
            'stats' => $this->simpleLine('(simulateur : pas de mesure de CPU ni de mémoire)'),
            'attach', 'wait', 'pause', 'unpause', 'rename', 'update', 'commit', 'save', 'load', 'export', 'import', 'diff', 'events', 'context', 'swarm', 'service', 'stack', 'node', 'secret', 'config', 'plugin', 'trust', 'scout', 'manifest' => $this->simpleLine(sprintf('docker %s : commande non simulée.', $command), 1),
            default => $this->unknown($command),
        };
    }

    private function simpleLine(string $text, int $code = 0): int
    {
        $this->line($text);

        return $code;
    }

    private function unknown(string $command): int
    {
        $this->line(sprintf("docker: unknown command: docker %s\n\nRun 'docker --help' for more information", $command));
        $premier = explode(' ', $command)[0];
        if (\in_array($premier, ['ls', 'cat', 'cd', 'pwd', 'mkdir', 'rm', 'cp', 'mv', 'vim', 'nano', 'php', 'composer', 'curl', 'grep', 'touch'], true)) {
            $this->line(sprintf("💡 Cette console ne comprend que les commandes docker. Pour « %s », deux chemins : l'explorateur de fichiers à gauche pour votre projet, ou « docker exec <conteneur> %s » pour l'intérieur d'un conteneur.", $premier, $command));
        }

        return 1;
    }

    private function version(): int
    {
        $this->line("Client:\n Version:           ".self::VERSION."\n API version:       1.51\n Go version:        go1.24.6\n OS/Arch:           linux/amd64\n Context:           default\n\nServer: Docker Engine - Community (simulateur forelse)\n Engine:\n  Version:          ".self::VERSION."\n  API version:      1.51 (minimum version 1.24)\n  OS/Arch:          linux/amd64\n containerd:\n  Version:          1.7.27\n runc:\n  Version:          1.2.5");

        return 0;
    }

    private function info(): int
    {
        $store = $this->docker->store;
        $running = \count(array_filter($store->containers, static fn (Container $c) => $c->isRunning()));
        $this->line(sprintf("Client:\n Version:    %s\n Context:    default\n\nServer:\n Containers: %d\n  Running: %d\n  Paused: 0\n  Stopped: %d\n Images: %d\n Server Version: %s\n Storage Driver: overlayfs (simulé)\n Cgroup Driver: cgroupfs\n Kernel Version: 6.10.14-linuxkit\n Operating System: Simulateur Docker (forelse), dans votre navigateur\n OSType: linux\n Architecture: x86_64\n CPUs: 4\n Total Memory: 7.66GiB\n Docker Root Dir: /var/lib/docker", self::VERSION, \count($store->containers), $running, \count($store->containers) - $running, \count($store->images), self::VERSION));

        return 0;
    }

    // --- Images ------------------------------------------------------------------------------

    private function build(array $argv): int
    {
        $args = Args::parse($argv, [
            't' => ['tag', true, true], 'tag' => ['tag', true, true], 'f' => ['file', true], 'file' => ['file', true],
            'target' => ['target', true], 'build-arg' => ['build-arg', true, true], 'no-cache' => ['no-cache', false],
            'progress' => ['progress', true], 'q' => ['quiet', false], 'quiet' => ['quiet', false], 'pull' => ['pull', false],
            'load' => ['load', false], 'platform' => ['platform', true], 'label' => ['label', true, true], 'network' => ['network', true],
            'secret' => ['secret', true, true], 'ssh' => ['ssh', true], 'cache-from' => ['cache-from', true, true], 'rm' => ['rm', false],
        ], 'build');
        if (\count($args->positional) !== 1) {
            throw new UsageError(sprintf("'docker buildx build' requires 1 argument\n\nUsage:  docker buildx build [OPTIONS] PATH | URL | -\n\nRun 'docker buildx build --help' for more information"), 1);
        }
        foreach ($args->all('tag') as $tag) {
            if (preg_match('/[A-Z]/', explode(':', $tag)[0]) || !preg_match('#^[a-zA-Z0-9][a-zA-Z0-9._/-]*(:[\w.-]+)?$#', $tag)) {
                $this->line(sprintf('ERROR: failed to build: invalid tag "%s": repository name must be lowercase', $tag));

                return 1;
            }
        }
        $buildArgs = [];
        foreach ($args->all('build-arg') as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, null);
            $buildArgs[$key] = $value ?? '';
        }
        $result = $this->docker->build($args->positional[0], $args->get('file'), $args->all('tag'), $args->get('target'), $buildArgs, $args->has('no-cache'));
        if ($args->has('quiet') && $result->success) {
            $this->line($result->image?->id ?? '');
        } else {
            $this->write($result->output);
        }
        if ($result->notes !== []) {
            $this->line('');
            foreach ($result->notes as $note) {
                $this->line('💡 '.$note);
            }
        }
        if ($result->success && $args->all('tag') === []) {
            $this->line('');
            $this->line('What\'s next:');
            $this->line('    L\'image n\'a pas de nom : ajoutez -t nom pour la retrouver dans docker images.');
        }

        return $result->success ? 0 : 1;
    }

    private function images(array $argv): int
    {
        $args = Args::parse($argv, ['a' => ['all', false], 'all' => ['all', false], 'q' => ['quiet', false], 'quiet' => ['quiet', false], 'filter' => ['filter', true, true], 'f' => ['filter', true, true], 'no-trunc' => ['no-trunc', false], 'format' => ['format', true], 'digests' => ['digests', false]], 'images');
        $danglingOnly = \in_array('dangling=true', $args->all('filter'), true);
        $rows = [];
        $images = $this->docker->store->images;
        uasort($images, static fn (Image $a, Image $b) => $b->createdAt <=> $a->createdAt);
        foreach ($images as $image) {
            $tags = $image->tags === [] ? ['<none>:<none>'] : $image->tags;
            foreach ($tags as $tag) {
                if ($danglingOnly && $tag !== '<none>:<none>') {
                    continue;
                }
                if (!$danglingOnly && $tag === '<none>:<none>' && !$args->has('all') && false) {
                    continue;
                }
                $separator = strrpos($tag, ':');
                $rows[] = [substr($tag, 0, $separator), substr($tag, $separator + 1), $args->has('no-trunc') ? $image->id : $image->shortId(), Format::ago($image->createdAt), Format::size($image->size())];
            }
        }
        if ($args->has('quiet')) {
            $this->write(implode("\n", array_unique(array_column($rows, 2))).($rows !== [] ? "\n" : ''));

            return 0;
        }
        $filter = $args->positional[0] ?? null;
        if ($filter !== null) {
            $rows = array_values(array_filter($rows, static fn ($r) => $r[0] === $filter || $r[0].':'.$r[1] === $filter));
        }
        $this->write(Format::table(['REPOSITORY', 'TAG', 'IMAGE ID', 'CREATED', 'SIZE'], $rows));

        return 0;
    }

    private function image(array $argv): int
    {
        $sub = array_shift($argv);

        return match ($sub) {
            'ls', 'list' => $this->images($argv),
            'rm', 'remove' => $this->rmi($argv),
            'pull' => $this->pull($argv),
            'push' => $this->push($argv),
            'build' => $this->build($argv),
            'tag' => $this->tag($argv),
            'history' => $this->history($argv),
            'inspect' => $this->inspect($argv, 'image'),
            'prune' => $this->imagePrune($argv),
            default => $this->unknown('image '.($sub ?? '')),
        };
    }

    private function rmi(array $argv): int
    {
        $args = Args::parse($argv, ['f' => ['force', false], 'force' => ['force', false], 'no-prune' => ['no-prune', false]], 'rmi');
        $code = 0;
        foreach ($args->positional as $reference) {
            $image = $this->docker->store->findImage($reference);
            if ($image === null) {
                $this->line(sprintf('Error response from daemon: No such image: %s', str_contains($reference, ':') ? $reference : $reference.':latest'));
                $code = 1;
                continue;
            }
            foreach ($this->docker->store->containers as $container) {
                if ($container->imageId === $image->id && !$args->has('force')) {
                    $this->line(sprintf('Error response from daemon: conflict: unable to delete %s (%s) - image is being used by %s container %s', $reference, $container->isRunning() ? 'cannot be forced' : 'must be forced', $container->isRunning() ? 'running' : 'stopped', $container->shortId()));
                    $code = 1;
                    continue 2;
                }
            }
            $normalized = Store::normalizeTag($reference);
            if (\count($image->tags) > 1 && \in_array($normalized, $image->tags, true)) {
                $image->tags = array_values(array_diff($image->tags, [$normalized]));
                $this->line('Untagged: '.$normalized);
                continue;
            }
            foreach ($image->tags as $tag) {
                $this->line('Untagged: '.$tag);
            }
            $this->docker->store->removeImage($image);
            $this->line('Deleted: '.$image->id);
        }

        return $code;
    }

    private function imagePrune(array $argv): int
    {
        $args = Args::parse($argv, ['a' => ['all', false], 'all' => ['all', false], 'f' => ['force', false], 'force' => ['force', false], 'filter' => ['filter', true, true]], 'image prune');
        $used = array_map(static fn (Container $c) => $c->imageId, $this->docker->store->containers);
        $reclaimed = 0;
        $deleted = [];
        foreach ($this->docker->store->images as $image) {
            if (\in_array($image->id, $used, true) || ($image->tags !== [] && !$args->has('all'))) {
                continue;
            }
            $reclaimed += $image->size();
            $deleted[] = $image;
            $this->docker->store->removeImage($image);
        }
        if (!$args->has('force')) {
            $this->line('WARNING! This will remove all '.($args->has('all') ? 'images without at least one container associated to them' : 'dangling images').'.');
        }
        if ($deleted !== []) {
            $this->line('Deleted Images:');
            foreach ($deleted as $image) {
                foreach ($image->tags as $tag) {
                    $this->line('untagged: '.$tag);
                }
                $this->line('deleted: '.$image->id);
            }
            $this->line('');
        }
        $this->line('Total reclaimed space: '.Format::size($reclaimed));

        return 0;
    }

    private function pull(array $argv): int
    {
        $args = Args::parse($argv, ['q' => ['quiet', false], 'quiet' => ['quiet', false], 'a' => ['all', false], 'platform' => ['platform', true]], 'pull');
        $reference = $args->positional[0] ?? throw new UsageError("\"docker pull\" requires exactly 1 argument.\nSee 'docker pull --help'.\n\nUsage:  docker pull [OPTIONS] NAME[:TAG|@DIGEST]\n\nPull an image or a repository from a registry", 1);
        try {
            [, $output] = $this->docker->pull($reference);
            $this->write($args->has('quiet') ? explode("\n", trim($output))[\count(explode("\n", trim($output))) - 1]."\n" : $output);

            return 0;
        } catch (UnknownImageException $e) {
            [$repository, $tag] = Catalog::split($reference);
            $this->line(str_contains($e->getMessage(), 'manifest') ? sprintf('Error response from daemon: manifest for %s:%s not found: manifest unknown: manifest unknown', $repository, $tag) : 'Error response from daemon: '.$e->getMessage());

            return 1;
        }
    }

    private function push(array $argv): int
    {
        $reference = $argv[0] ?? '';
        $this->line(sprintf("The push refers to repository [docker.io/%s]\nAn image does not exist locally with the tag: %s", str_contains($reference, '/') ? explode(':', $reference)[0] : 'library/'.explode(':', $reference)[0], $reference));
        if ($this->docker->store->findImage($reference) !== null) {
            $this->output = sprintf("The push refers to repository [docker.io/%s]\npush access denied, repository does not exist or may require authorization: server message: insufficient_scope: authorization failed\n", str_contains($reference, '/') ? explode(':', $reference)[0] : 'library/'.explode(':', $reference)[0]);
            $this->line('💡 Le simulateur n\'a pas de registre : docker push ne peut pas publier l\'image.');
        }

        return 1;
    }

    private function tag(array $argv): int
    {
        if (\count($argv) !== 2) {
            throw new UsageError("\"docker tag\" requires exactly 2 arguments.\nSee 'docker tag --help'.\n\nUsage:  docker tag SOURCE_IMAGE[:TAG] TARGET_IMAGE[:TAG]", 1);
        }
        $image = $this->docker->store->findImage($argv[0]) ?? throw new DockerException(sprintf('No such image: %s', Store::normalizeTag($argv[0])), 1);
        $target = Store::normalizeTag($argv[1]);
        foreach ($this->docker->store->images as $other) {
            $other->tags = array_values(array_diff($other->tags, [$target]));
        }
        $image->tags[] = $target;

        return 0;
    }

    private function history(array $argv): int
    {
        $args = Args::parse($argv, ['no-trunc' => ['no-trunc', false], 'H' => ['human', false], 'human' => ['human', false], 'q' => ['quiet', false]], 'history');
        $image = $this->docker->store->findImage($args->positional[0] ?? '') ?? throw new DockerException(sprintf('No such image: %s', $args->positional[0] ?? ''), 1);
        $rows = [];
        foreach (array_reverse($image->layers) as $index => $layer) {
            $created = $layer->createdBy;
            if (!$args->has('no-trunc') && mb_strlen($created) > 45) {
                $created = mb_substr($created, 0, 44).'…';
            }
            $rows[] = [$index === 0 ? $image->shortId() : '<missing>', Format::ago($layer->createdAt ?: $image->createdAt), $created, $layer->empty ? '0B' : Format::size($layer->size), 'buildkit.dockerfile.v0']; // toutes les couches viennent de BuildKit, instructions sans contenu comprises
        }
        $this->write(Format::table(['IMAGE', 'CREATED', 'CREATED BY', 'SIZE', 'COMMENT'], $rows));

        return 0;
    }

    private function search(array $argv): int
    {
        $term = $argv[0] ?? '';
        $rows = [];
        foreach (['php' => 'While designed for web development, the PHP scripting language also provides general-purpose use.', 'composer' => 'Composer is a dependency manager written in and for PHP.', 'nginx' => 'Official build of Nginx.', 'postgres' => 'The PostgreSQL object-relational database system provides reliability and data integrity.', 'mysql' => 'MySQL is a widely used, open-source relational database management system (RDBMS).', 'mariadb' => 'MariaDB Server is a high performing open source relational database, forked from MySQL.', 'redis' => 'Redis is the world’s fastest data platform for caching, vector search, and NoSQL databases.', 'node' => 'Node.js is a JavaScript-based platform for server-side and networking applications.', 'alpine' => 'A minimal Docker image based on Alpine Linux with a complete package index and only 5 MB in size!', 'debian' => 'Debian is a Linux distribution that\'s composed entirely of free and open-source software.', 'axllent/mailpit' => 'An email and SMTP testing tool with API for developers', 'adminer' => 'Database management in a single PHP file.', 'caddy' => 'Caddy 2 is a powerful, enterprise-ready, open source web server with automatic HTTPS written in Go.', 'dunglas/frankenphp' => 'The modern PHP app server'] as $name => $description) {
            if ($term === '' || str_contains($name, strtolower($term))) {
                $rows[] = [$name, mb_strlen($description) > 44 ? mb_substr($description, 0, 44).'…' : $description, (string) (1000 + crc32($name) % 9000), str_contains($name, '/') ? '' : '[OK]'];
            }
        }
        $this->write(Format::table(['NAME', 'DESCRIPTION', 'STARS', 'OFFICIAL'], $rows));

        return 0;
    }

    // --- Conteneurs ----------------------------------------------------------------------------

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
            $this->line(sprintf("docker: invalid reference format: repository name (library/%s) must be lowercase\n\nRun 'docker %s --help' for more information", $reference, $command));

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
                $this->line(sprintf("docker: open %s: no such file or directory\n\nRun 'docker %s --help' for more information", $file, $command));

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
        foreach ($args->all('publish') as $publish) {
            $port = $this->parsePort($publish, $command);
            if ($port === null) {
                return 125;
            }
            $spec->ports[] = $port;
        }
        $spec->publishAll = $args->has('publish-all');
        foreach ($args->all('volume') as $volume) {
            $mount = $this->parseVolume($volume);
            if ($mount === null) {
                $this->line(sprintf("docker: invalid spec: %s: empty section between colons\n\nRun 'docker %s --help' for more information", $volume, $command));

                return 125;
            }
            $spec->mounts[] = $mount;
        }
        foreach ($args->all('mount') as $mount) {
            $options = [];
            foreach (explode(',', $mount) as $pair) {
                [$key, $value] = array_pad(explode('=', $pair, 2), 2, 'true');
                $options[$key] = $value;
            }
            $type = $options['type'] ?? 'volume';
            $source = $options['source'] ?? ($options['src'] ?? '');
            if ($type === 'bind' && !file_exists($this->docker->hostPath($source))) {
                $this->line(sprintf("docker: Error response from daemon: invalid mount config for type \"bind\": bind source path does not exist: %s\n\nRun 'docker %s --help' for more information", $this->docker->hostPath($source), $command));

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
            $this->write($pullOutput);
            $this->line(sprintf("docker: Error response from daemon: %s\n\nRun 'docker %s --help' for more information", $e->getMessage(), $command));

            return 125;
        }
        $this->write($pullOutput);
        if ($createOnly) {
            $this->line($container->id);

            return 0;
        }
        try {
            $this->docker->start($container);
        } catch (DockerException $e) {
            if ($args->has('detach')) {
                $this->line($container->id);
            }
            $this->line(sprintf("docker: Error response from daemon: %s\n\nRun 'docker run --help' for more information", $e->getMessage()));

            return $e->exitCode;
        }
        if ($args->has('detach')) {
            $this->line($container->id);

            return 0;
        }
        if ($args->has('interactive') && $args->has('tty') && $container->isRunning() && $container->process === 'idle') {
            $this->write(implode("\n", $container->logs).($container->logs !== [] ? "\n" : ''));
            $this->line(sprintf('💡 La console du simulateur n\'est pas un terminal : impossible d\'entrer dans le conteneur. Il tourne en arrière-plan (%s) ; lancez vos commandes avec docker exec %s <commande>.', $container->name, $container->name));

            return 0;
        }
        $this->write(implode("\n", $container->logs).($container->logs !== [] ? "\n" : ''));
        if ($container->isRunning()) {
            $this->line(sprintf('💡 Le conteneur %s tourne. Un vrai terminal resterait attaché à ses journaux (Ctrl+C l\'arrêterait) ; ici la main vous est rendue et il continue en arrière-plan : docker ps, docker logs %s, docker stop %s.', $container->name, $container->name, $container->name));

            return 0;
        }

        return $container->exitCode;
    }

    /** @return array{host: int, container: int, protocol: string, ip: string}|null */
    private function parsePort(string $publish, string $command): ?array
    {
        $protocol = 'tcp';
        if (str_contains($publish, '/')) {
            [$publish, $protocol] = explode('/', $publish, 2);
        }
        $parts = explode(':', $publish);
        $ip = '0.0.0.0';
        if (\count($parts) === 3) {
            $ip = array_shift($parts);
        }
        if (\count($parts) === 1) {
            $parts = [(string) (32768 + random_int(0, 20000)), $parts[0]];
        }
        [$host, $container] = $parts;
        if (!ctype_digit($host) || !ctype_digit($container) || (int) $container < 1 || (int) $container > 65535 || (int) $host > 65535) {
            $this->line(sprintf("docker: invalid containerPort: %s\n\nRun 'docker %s --help' for more information", $container, $command));

            return null;
        }

        return ['host' => (int) $host, 'container' => (int) $container, 'protocol' => $protocol, 'ip' => $ip === '' ? '0.0.0.0' : $ip];
    }

    /** @return array{type: string, source: string, target: string, readOnly: bool}|null */
    private function parseVolume(string $volume): ?array
    {
        $parts = explode(':', $volume);
        if (\in_array('', $parts, true)) {
            return null;
        }
        if (\count($parts) === 1) {
            return ['type' => 'volume', 'source' => '', 'target' => $parts[0], 'readOnly' => false];
        }
        [$source, $target] = $parts;
        $readOnly = \in_array('ro', explode(',', $parts[2] ?? ''), true);
        if (str_starts_with($source, '/') || str_starts_with($source, '.') || str_starts_with($source, '~')) {
            return ['type' => 'bind', 'source' => $this->docker->hostPath($source), 'target' => $target, 'readOnly' => $readOnly];
        }
        if (!str_starts_with($target, '/')) {
            return null;
        }

        return ['type' => 'volume', 'source' => $source, 'target' => $target, 'readOnly' => $readOnly];
    }

    private function findContainer(string $name): Container
    {
        return $this->docker->store->findContainer($name) ?? throw new DockerException(sprintf('No such container: %s', $name), 1);
    }

    private function start(array $argv): int
    {
        $args = Args::parse($argv, ['a' => ['attach', false], 'attach' => ['attach', false], 'i' => ['interactive', false]], 'start');
        $code = 0;
        foreach ($args->positional as $name) {
            $container = $this->docker->store->findContainer($name);
            if ($container === null) {
                $this->line(sprintf('Error response from daemon: No such container: %s', $name));
                $code = 1;
                continue;
            }
            $before = \count($container->logs);
            try {
                $this->docker->start($container);
            } catch (DockerException $e) {
                $this->line(sprintf('Error response from daemon: %s', $e->getMessage()));
                $this->line(sprintf('Error: failed to start containers: %s', $name));
                $code = 1;
                continue;
            }
            if ($args->has('attach')) {
                $this->write(implode("\n", \array_slice($container->logs, $before))."\n");
                $code = $container->isRunning() ? 0 : $container->exitCode;
            } else {
                $this->line($name);
            }
        }

        return $code;
    }

    private function stop(array $argv, string $command): int
    {
        $args = Args::parse($argv, ['t' => ['time', true], 'time' => ['time', true], 's' => ['signal', true], 'signal' => ['signal', true]], $command);
        if ($args->positional === []) {
            throw new UsageError(sprintf("\"docker %s\" requires at least 1 argument.\nSee 'docker %s --help'.", $command, $command), 1);
        }
        $code = 0;
        foreach ($args->positional as $name) {
            $container = $this->docker->store->findContainer($name);
            if ($container === null) {
                $this->line(sprintf('Error response from daemon: No such container: %s', $name));
                $code = 1;
                continue;
            }
            if ($command === 'kill' && !$container->isRunning()) {
                $this->line(sprintf('Error response from daemon: cannot kill container: %s: container %s is not running', $name, $container->id));
                $code = 1;
                continue;
            }
            $this->docker->stop($container);
            if ($command === 'kill') {
                $container->exitCode = 137;
            }
            $this->line($name);
        }

        return $code;
    }

    private function restart(array $argv): int
    {
        $args = Args::parse($argv, ['t' => ['time', true], 'time' => ['time', true]], 'restart');
        $code = 0;
        foreach ($args->positional as $name) {
            try {
                $this->docker->restart($this->findContainer($name));
                $this->line($name);
            } catch (DockerException $e) {
                $this->line('Error response from daemon: '.$e->getMessage());
                $code = 1;
            }
        }

        return $code;
    }

    private function rm(array $argv): int
    {
        $args = Args::parse($argv, ['f' => ['force', false], 'force' => ['force', false], 'v' => ['volumes', false], 'volumes' => ['volumes', false], 'l' => ['link', false]], 'rm');
        if ($args->positional === []) {
            throw new UsageError("\"docker rm\" requires at least 1 argument.\nSee 'docker rm --help'.\n\nUsage:  docker rm [OPTIONS] CONTAINER [CONTAINER...]\n\nRemove one or more containers", 1);
        }
        $code = 0;
        foreach ($args->positional as $name) {
            $container = $this->docker->store->findContainer($name);
            if ($container === null) {
                $this->line(sprintf('Error response from daemon: No such container: %s', $name));
                $code = 1;
                continue;
            }
            try {
                $this->docker->remove($container, $args->has('force'), $args->has('volumes'));
                $this->line($name);
            } catch (DockerException $e) {
                $this->line('Error response from daemon: '.$e->getMessage());
                $code = 1;
            }
        }

        return $code;
    }

    private function ps(array $argv): int
    {
        $args = Args::parse($argv, ['a' => ['all', false], 'all' => ['all', false], 'q' => ['quiet', false], 'quiet' => ['quiet', false], 'no-trunc' => ['no-trunc', false], 'filter' => ['filter', true, true], 'f' => ['filter', true, true], 's' => ['size', false], 'format' => ['format', true], 'l' => ['latest', false], 'n' => ['last', true]], 'ps');
        $containers = array_values(array_filter($this->docker->store->containers, static fn (Container $c) => $args->has('all') || $c->isRunning() || $c->status === Container::RESTARTING));
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
        $rank = array_flip(array_keys($this->docker->store->containers));
        usort($containers, static fn (Container $a, Container $b) => [$b->createdAt, $rank[$b->id] ?? 0] <=> [$a->createdAt, $rank[$a->id] ?? 0]);
        foreach ($containers as $container) {
            $this->docker->refreshHealth($container);
        }
        if ($args->has('quiet')) {
            $this->write(implode('', array_map(static fn (Container $c) => $c->shortId()."\n", $containers)));

            return 0;
        }
        $format = (string) $args->get('format', '');
        if ($format !== '' && $format !== 'table') {
            $table = str_starts_with($format, 'table ');
            $template = str_replace(['\\t', '\\n'], ["\t", "\n"], $table ? substr($format, 6) : $format);
            $fields = fn (Container $c) => ['ID' => $c->shortId(), 'Image' => $this->imageLabel($c), 'Command' => Format::command($c), 'RunningFor' => Format::ago($c->createdAt), 'Status' => Format::status($c), 'State' => $c->isRunning() ? 'running' : $c->status, 'Ports' => Format::ports($c, exposed: $this->docker->store->images[$c->imageId]->config->exposed ?? []), 'Names' => $c->name, 'Networks' => implode(',', array_keys($c->networks))];
            $render = static fn (array $values) => preg_replace_callback('/\{\{\s*\.(\w+)\s*\}\}/', static fn ($m) => (string) ($values[$m[1]] ?? ''), $template);
            if ($table) {
                $this->write((string) preg_replace_callback('/\{\{\s*\.(\w+)\s*\}\}/', static fn ($m) => strtoupper((string) preg_replace('/(?<!^)[A-Z]/', ' $0', $m[1])), $template)."\n");
            }
            foreach ($containers as $c) {
                $this->write($render($fields($c))."\n");
            }

            return 0;
        }
        $rows = array_map(fn (Container $c) => [$c->shortId(), $this->imageLabel($c), Format::command($c), Format::ago($c->createdAt), Format::status($c), Format::ports($c, exposed: $this->docker->store->images[$c->imageId]->config->exposed ?? []), $c->name], $containers);
        $this->write(Format::table(['CONTAINER ID', 'IMAGE', 'COMMAND', 'CREATED', 'STATUS', 'PORTS', 'NAMES'], $rows));

        return 0;
    }

    private function imageLabel(Container $container): string
    {
        $image = $this->docker->store->images[$container->imageId] ?? null;
        if ($image === null) {
            return $container->imageRef;
        }
        $reference = Store::normalizeTag($container->imageRef);
        if (\in_array($reference, $image->tags, true)) {
            return str_ends_with($container->imageRef, ':latest') || !str_contains($container->imageRef, ':') ? preg_replace('/:latest$/', '', $container->imageRef) : $container->imageRef;
        }

        return $image->shortId();
    }

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
            'inspect' => $this->inspect($argv, 'container'),
            'port' => $this->port($argv),
            'top' => $this->top($argv),
            'cp' => $this->cp($argv),
            'prune' => $this->containerPrune($argv),
            default => $this->unknown('container '.($sub ?? '')),
        };
    }

    private function containerPrune(array $argv): int
    {
        $args = Args::parse($argv, ['f' => ['force', false], 'force' => ['force', false], 'filter' => ['filter', true, true]], 'container prune');
        if (!$args->has('force')) {
            $this->line('WARNING! This will remove all stopped containers.');
        }
        $deleted = [];
        foreach ($this->docker->store->containers as $container) {
            if (!$container->isRunning()) {
                $deleted[] = $container->id;
                $this->docker->remove($container, true);
            }
        }
        if ($deleted !== []) {
            $this->line("Deleted Containers:\n".implode("\n", $deleted)."\n");
        }
        $this->line('Total reclaimed space: 0B');

        return 0;
    }

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
        $this->write($lines === [] ? '' : implode("\n", $lines)."\n");
        if ($args->has('follow')) {
            $this->line('💡 (simulateur : -f ne suit pas les journaux en continu ; relancez la commande pour voir les nouvelles lignes)');
        }

        return 0;
    }

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
            $this->line($e->getMessage());

            return $e->exitCode;
        }
        $this->write($output);
        if ($interactiveShell && $code === 0) {
            $this->line(sprintf('💡 La console du simulateur n\'est pas un terminal : pas de session interactive. Passez la commande directement, par exemple : docker exec %s %s', $name, basename($argv[0]) === 'psql' ? 'psql -U app -c "\\l"' : 'sh -c "ls -la"'));
        }

        return $code;
    }

    private function inspect(array $argv, ?string $type = null): int
    {
        $args = Args::parse($argv, ['f' => ['format', true], 'format' => ['format', true], 'type' => ['type', true], 's' => ['size', false]], 'inspect');
        $type ??= $args->get('type');
        $results = [];
        foreach ($args->positional as $name) {
            $object = null;
            if ($type === null || $type === 'container') {
                $container = $this->docker->store->findContainer($name);
                if ($container !== null) {
                    $this->docker->refreshHealth($container);
                }
                $object = $container !== null ? Inspect::container($this->docker, $container) : null;
            }
            if ($object === null && ($type === null || $type === 'image')) {
                $image = $this->docker->store->findImage($name);
                $object = $image !== null ? Inspect::image($image) : null;
            }
            if ($object === null && ($type === null || $type === 'network') && isset($this->docker->store->networks[$name])) {
                $object = Inspect::network($this->docker, $this->docker->store->networks[$name]);
            }
            if ($object === null && ($type === null || $type === 'volume') && isset($this->docker->store->volumes[$name])) {
                $object = Inspect::volume($this->docker, $this->docker->store->volumes[$name]);
            }
            if ($object === null) {
                $this->line(sprintf('[]\nError: No such object: %s', $name));

                return 1;
            }
            $results[] = $object;
        }
        if ($args->get('format') !== null) {
            foreach ($results as $result) {
                $this->line(Inspect::format((string) $args->get('format'), $result));
            }

            return 0;
        }
        $this->line(json_encode($results, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));

        return 0;
    }

    private function port(array $argv): int
    {
        $container = $this->findContainer($argv[0] ?? '');
        foreach ($container->ports as $port) {
            if (!isset($argv[1]) || (int) $argv[1] === $port['container']) {
                $this->line(sprintf('%d/%s -> %s:%d', $port['container'], $port['protocol'], $port['ip'], $port['host']));
                if ($port['ip'] === '0.0.0.0') {
                    $this->line(sprintf('%d/%s -> [::]:%d', $port['container'], $port['protocol'], $port['host']));
                }
            }
        }

        return 0;
    }

    private function top(array $argv): int
    {
        $container = $this->findContainer($argv[0] ?? '');
        if (!$container->isRunning()) {
            throw new DockerException(sprintf('container %s is not running', $container->id), 1);
        }
        $command = implode(' ', $container->processOptions['argv'] ?? $container->command);
        $rows = [[$container->user ?? 'root', '12345', '12320', '0', '10:00', '?', '00:00:00', $command]];
        if (\in_array($container->process, ['apache', 'php-fpm', 'nginx'], true)) {
            for ($i = 0; $i < 2; ++$i) {
                $rows[] = [$container->process === 'nginx' ? 'nginx' : 'www-data', (string) (12350 + $i), '12345', '0', '10:00', '?', '00:00:00', $container->process === 'php-fpm' ? 'php-fpm: pool www' : ($container->process === 'nginx' ? 'nginx: worker process' : 'apache2 -DFOREGROUND')];
            }
        }
        $this->write(Format::table(['UID', 'PID', 'PPID', 'C', 'STIME', 'TTY', 'TIME', 'CMD'], $rows));

        return 0;
    }

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
            $this->line(sprintf('Successfully copied %s to %s', Format::size($fs->size($source)), $to));

            return 0;
        }
        if (preg_match('/^([^\/:]+):(.+)$/', $to, $m)) {
            $container = $this->findContainer($m[1]);
            $fs = $this->docker->fs($container);
            $source = $this->docker->hostPath($from);
            if (!file_exists($source)) {
                $this->line(sprintf('lstat %s: no such file or directory', $source));

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
            $this->line(sprintf('Successfully copied %s to %s', Format::size((int) @filesize($source)), $to));

            return 0;
        }
        throw new UsageError("must specify at least one container source\nSee 'docker cp --help'.", 1);
    }

    // --- Réseaux, volumes, système -------------------------------------------------------------

    private function network(array $argv): int
    {
        $sub = array_shift($argv);
        $store = $this->docker->store;
        switch ($sub) {
            case 'ls':
            case 'list':
                $rows = array_map(static fn ($n) => [substr($n->id, 0, 12), $n->name, $n->driver, 'local'], array_values($store->networks));
                $this->write(Format::table(['NETWORK ID', 'NAME', 'DRIVER', 'SCOPE'], $rows));

                return 0;
            case 'create':
                $args = Args::parse($argv, ['d' => ['driver', true], 'driver' => ['driver', true], 'subnet' => ['subnet', true], 'label' => ['label', true, true], 'internal' => ['internal', false], 'attachable' => ['attachable', false]], 'network create');
                $name = $args->positional[0] ?? throw new UsageError("\"docker network create\" requires exactly 1 argument.\nSee 'docker network create --help'.", 1);
                $this->line($this->docker->createNetwork($name)->id);

                return 0;
            case 'rm':
            case 'remove':
                $code = 0;
                foreach ($argv as $name) {
                    try {
                        $this->docker->removeNetwork($name);
                        $this->line($name);
                    } catch (DockerException $e) {
                        $this->line('Error response from daemon: '.$e->getMessage());
                        $code = 1;
                    }
                }

                return $code;
            case 'inspect':
                return $this->inspect($argv, 'network');
            case 'connect':
                $args = Args::parse($argv, ['alias' => ['alias', true, true], 'ip' => ['ip', true]], 'network connect');
                $this->docker->connect($this->findContainer($args->positional[1] ?? ''), $args->positional[0] ?? '', $args->all('alias'));

                return 0;
            case 'disconnect':
                $container = $this->findContainer($argv[1] ?? '');
                unset($container->networks[$argv[0] ?? ''], $container->ips[$argv[0] ?? '']);

                return 0;
            case 'prune':
                $removed = [];
                foreach ($store->networks as $name => $network) {
                    if ($network->builtin) {
                        continue;
                    }
                    foreach ($store->containers as $container) {
                        if (isset($container->networks[$name])) {
                            continue 2;
                        }
                    }
                    unset($store->networks[$name]);
                    $removed[] = $name;
                }
                $this->line("WARNING! This will remove all custom networks not used by at least one container.\n".($removed !== [] ? "Deleted Networks:\n".implode("\n", $removed) : ''));

                return 0;
            default:
                return $this->unknown('network '.($sub ?? ''));
        }
    }

    private function volume(array $argv): int
    {
        $sub = array_shift($argv);
        $store = $this->docker->store;
        switch ($sub) {
            case 'ls':
            case 'list':
                $args = Args::parse($argv, ['q' => ['quiet', false], 'quiet' => ['quiet', false], 'f' => ['filter', true, true], 'filter' => ['filter', true, true]], 'volume ls');
                $volumes = array_values($store->volumes);
                if (\in_array('dangling=true', $args->all('filter'), true)) {
                    $volumes = array_values(array_filter($volumes, fn ($v) => !$this->volumeInUse($v->name)));
                }
                if ($args->has('quiet')) {
                    $this->write(implode('', array_map(static fn ($v) => $v->name."\n", $volumes)));

                    return 0;
                }
                $this->write(Format::table(['DRIVER', 'VOLUME NAME'], array_map(static fn ($v) => ['local', $v->name], $volumes)));

                return 0;
            case 'create':
                $name = $argv[0] ?? hash('sha256', random_bytes(16));
                $this->docker->createVolume($name);
                $this->line($name);

                return 0;
            case 'rm':
            case 'remove':
                $args = Args::parse($argv, ['f' => ['force', false], 'force' => ['force', false]], 'volume rm');
                $code = 0;
                foreach ($args->positional as $name) {
                    try {
                        $existait = isset($store->volumes[$name]);
                        $this->docker->removeVolume($name, $args->has('force'));
                        if ($existait) {
                            $this->line($name);
                        }
                    } catch (DockerException $e) {
                        $this->line('Error response from daemon: '.$e->getMessage());
                        $code = 1;
                    }
                }

                return $code;
            case 'inspect':
                return $this->inspect($argv, 'volume');
            case 'prune':
                $args = Args::parse($argv, ['a' => ['all', false], 'all' => ['all', false], 'f' => ['force', false], 'force' => ['force', false], 'filter' => ['filter', true, true]], 'volume prune');
                $removed = [];
                foreach ($store->volumes as $name => $volume) {
                    if (!$this->volumeInUse($name) && ($volume->anonymous || $args->has('all'))) {
                        $store->removeVolume($name);
                        $removed[] = $name;
                    }
                }
                if (!$args->has('force')) {
                    $this->line('WARNING! This will remove anonymous local volumes not used by at least one container.');
                }
                $this->line(($removed !== [] ? "Deleted Volumes:\n".implode("\n", $removed)."\n\n" : '').'Total reclaimed space: 0B');

                return 0;
            default:
                return $this->unknown('volume '.($sub ?? ''));
        }
    }

    private function volumeInUse(string $name): bool
    {
        foreach ($this->docker->store->containers as $container) {
            foreach ($container->mounts as $mount) {
                if ($mount['type'] === 'volume' && $mount['source'] === $name) {
                    return true;
                }
            }
        }

        return false;
    }

    private function system(array $argv): int
    {
        $sub = array_shift($argv);
        $store = $this->docker->store;
        if ($sub === 'df') {
            $imagesSize = array_sum(array_map(static fn (Image $i) => $i->size(), $store->images));
            $active = \count(array_unique(array_map(static fn (Container $c) => $c->imageId, $store->containers)));
            $this->write(Format::table(['TYPE', 'TOTAL', 'ACTIVE', 'SIZE', 'RECLAIMABLE'], [
                ['Images', (string) \count($store->images), (string) $active, Format::size($imagesSize), Format::size((int) ($imagesSize * 0.3))],
                ['Containers', (string) \count($store->containers), (string) \count(array_filter($store->containers, static fn (Container $c) => $c->isRunning())), '0B', '0B'],
                ['Local Volumes', (string) \count($store->volumes), (string) \count(array_filter(array_keys($store->volumes), fn ($n) => $this->volumeInUse($n))), '0B', '0B'],
                ['Build Cache', (string) \count($store->buildCache), '0', Format::size(\count($store->buildCache) * 12_000_000), Format::size(\count($store->buildCache) * 12_000_000)],
            ]));

            return 0;
        }
        if ($sub === 'prune') {
            $args = Args::parse($argv, ['a' => ['all', false], 'all' => ['all', false], 'f' => ['force', false], 'force' => ['force', false], 'volumes' => ['volumes', false]], 'system prune');
            $lines = ['WARNING! This will remove:', '  - all stopped containers', '  - all networks not used by at least one container'];
            if ($args->has('volumes')) {
                $lines[] = '  - all anonymous volumes not used by at least one container';
            }
            $lines[] = $args->has('all') ? '  - all images without at least one container associated to them' : '  - all dangling images';
            $lines[] = $args->has('all') ? '  - all build cache' : '  - unused build cache';
            $this->line(implode("\n", $lines)."\n");
            foreach ($store->containers as $container) {
                if (!$container->isRunning()) {
                    $this->docker->remove($container, true);
                }
            }
            $this->network(['prune']);
            $this->output = implode("\n", $lines)."\n\n";
            $used = array_map(static fn (Container $c) => $c->imageId, $store->containers);
            $reclaimed = 0;
            foreach ($store->images as $image) {
                if (!\in_array($image->id, $used, true) && ($image->tags === [] || $args->has('all'))) {
                    $reclaimed += $image->size();
                    $store->removeImage($image);
                }
            }
            if ($args->has('volumes')) {
                foreach ($store->volumes as $name => $volume) {
                    if ($volume->anonymous && !$this->volumeInUse($name)) {
                        $store->removeVolume($name);
                    }
                }
            }
            $cache = \count($store->buildCache);
            $store->buildCache = [];
            $this->line('Total reclaimed space: '.Format::size($reclaimed + $cache * 12_000_000));

            return 0;
        }
        if ($sub === 'info') {
            return $this->info();
        }

        return $this->unknown('system '.($sub ?? ''));
    }
}
