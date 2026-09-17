<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Cli;

use Forelse\DockerSim\Compose\ComposeException;
use Forelse\DockerSim\Compose\ComposeFile;
use Forelse\DockerSim\Compose\Project;
use Forelse\DockerSim\Engine\Docker;
use Forelse\DockerSim\State\Container;
use Symfony\Component\Yaml\Yaml;

/** docker compose … */
final class ComposeCommand
{
    public const VERSION = 'v2.39.2';

    /** @param array<string,string> $environment variables posées devant la commande */
    public function __construct(private readonly Docker $docker, private readonly array $environment = [])
    {
    }

    public function run(array $argv, string &$output): int
    {
        $global = [];
        $profiles = array_values(array_filter(explode(',', (string) (getenv('COMPOSE_PROFILES') ?: ''))));
        $files = [];
        $projectName = null;
        $envFile = null;
        while ($argv !== [] && str_starts_with($argv[0], '-')) {
            $option = array_shift($argv);
            if (\in_array($option, ['-f', '--file'], true)) {
                $files[] = (string) array_shift($argv);
            } elseif (str_starts_with($option, '--file=')) {
                $files[] = substr($option, 7);
            } elseif (\in_array($option, ['-p', '--project-name'], true)) {
                $projectName = (string) array_shift($argv);
            } elseif ($option === '--env-file') {
                $envFile = (string) array_shift($argv);
            } elseif ($option === '--profile') {
                $profiles[] = (string) array_shift($argv);
            } elseif (str_starts_with($option, '--profile=')) {
                $profiles[] = substr($option, 10);
            } elseif (\in_array($option, ['--progress', '--ansi', '--project-directory'], true)) {
                $global[$option] = array_shift($argv);
            } elseif ($option === '--version' || $option === '-v') {
                $output .= 'Docker Compose version '.self::VERSION."\n";

                return 0;
            }
        }
        $command = array_shift($argv);
        if ($command === null || $command === 'help') {
            $output .= Help::command('compose', [])."\n";

            return 0;
        }
        if ($command === 'version') {
            $output .= 'Docker Compose version '.self::VERSION."\n";

            return 0;
        }
        if ($command === 'ls') {
            return $this->ls($output);
        }
        try {
            $file = ComposeFile::load($this->docker->projectDirectory, $files === [] ? null : $files, $projectName, $this->environment, $envFile);
        } catch (ComposeException $e) {
            // Compose affiche les avertissements déjà relevés avant de refuser le fichier.
            foreach ($e->warnings as $warning) {
                $output .= 'WARN[0000] '.$warning."\n";
            }
            $output .= $e->getMessage()."\n";

            return $e->getMessage() === 'no configuration file provided: not found' ? 14 : 15;
        }
        $project = new Project($this->docker, $file, $profiles);

        try {
            $code = match ($command) {
                'up' => $this->up($project, $argv),
                'down' => $this->down($project, $argv),
                'ps' => $this->ps($project, $argv),
                'logs' => $this->logs($project, $argv),
                'exec' => $this->exec($project, $argv),
                'run' => $this->runService($project, $argv),
                'build' => $this->build($project, $argv),
                'config' => $this->config($project, $argv),
                'stop' => $project->stop($this->services($argv)) ? 0 : 1,
                'start' => $project->start($this->services($argv)) ? 0 : 1,
                'restart' => $project->restart($this->services($argv)) ? 0 : 1,
                'pull' => $this->pull($project, $argv),
                'images' => $this->images($project),
                'top' => $this->top($project),
                'rm' => $this->rm($project, $argv),
                'port' => $this->port($project, $argv),
                'watch' => $project->fail("docker compose watch : non disponible dans le simulateur (utilisez un volume monté pour voir vos modifications sans reconstruire).", 1),
                'create' => $project->up($this->services($argv), true) ? 0 : 1,
                default => $project->fail(sprintf("unknown docker command: \"compose %s\"", $command), 1),
            };
        } catch (ComposeException $e) {
            $project->fail($e->getMessage());
            $code = 1;
        } catch (UsageError $e) {
            $project->fail($e->getMessage());
            $code = 1;
        }
        $output .= $project->output;

        return $code;
    }

    /** @return list<string> */
    private function services(array $argv): array
    {
        return array_values(array_filter($argv, static fn ($a) => !str_starts_with($a, '-')));
    }

    private function up(Project $project, array $argv): int
    {
        $args = Args::parse($argv, ['d' => ['detach', false], 'detach' => ['detach', false], 'build' => ['build', false], 'no-build' => ['no-build', false], 'force-recreate' => ['force-recreate', false], 'no-deps' => ['no-deps', false], 'wait' => ['wait', false], 'remove-orphans' => ['remove-orphans', false], 'pull' => ['pull', true], 'no-recreate' => ['no-recreate', false], 'abort-on-container-exit' => ['abort', false], 'quiet-pull' => ['quiet-pull', false], 'y' => ['yes', false], 'watch' => ['watch', false], 'w' => ['watch', false]], 'compose up');
        $ok = $project->up($args->positional, $args->has('detach') || $args->has('wait'), $args->has('build'), $args->has('force-recreate'), $args->has('no-deps'), $args->has('wait'), $args->has('no-build'));
        if ($ok && !$args->has('detach') && !$args->has('wait')) {
            $project->output .= "💡 Un vrai terminal resterait attaché aux journaux (Ctrl+C arrêterait tout). Ici la main vous est rendue et les conteneurs continuent de tourner : docker compose ps, docker compose logs, docker compose down.\n";
        }

        return $ok ? 0 : ($project->exitCode ?: 1);
    }

    private function down(Project $project, array $argv): int
    {
        $args = Args::parse($argv, ['v' => ['volumes', false], 'volumes' => ['volumes', false], 'remove-orphans' => ['remove-orphans', false], 'rmi' => ['rmi', true], 't' => ['timeout', true]], 'compose down');

        return $project->down($args->has('volumes'), $args->has('remove-orphans'), $args->get('rmi')) ? 0 : 1;
    }

    private function ps(Project $project, array $argv): int
    {
        $args = Args::parse($argv, ['a' => ['all', false], 'all' => ['all', false], 'q' => ['quiet', false], 'quiet' => ['quiet', false], 'services' => ['services', false], 'format' => ['format', true], 'status' => ['status', true, true]], 'compose ps');
        $containers = array_values(array_filter($project->containers(), static fn (Container $c) => $args->has('all') || $c->isRunning() || $c->status === Container::RESTARTING));
        foreach ($containers as $container) {
            $this->docker->refreshHealth($container);
        }
        if ($args->positional !== []) {
            $containers = array_values(array_filter($containers, static fn (Container $c) => \in_array($c->composeService(), $args->positional, true)));
        }
        if ($args->has('quiet')) {
            $project->output .= implode('', array_map(static fn (Container $c) => $c->shortId()."\n", $containers));

            return 0;
        }
        if ($args->has('services')) {
            $project->output .= implode('', array_map(static fn (Container $c) => $c->composeService()."\n", $containers));

            return 0;
        }
        $format = (string) $args->get('format', '');
        if ($format !== '' && !\in_array($format, ['json', 'JSON', 'table'], true)) {
            $table = str_starts_with($format, 'table ');
            $template = $table ? substr($format, 6) : $format;
            $fields = fn (Container $c) => ['Name' => $c->name, 'Service' => (string) $c->composeService(), 'Image' => preg_replace('/:latest$/', '', $c->imageRef) ?? $c->imageRef, 'State' => $c->isRunning() ? 'running' : $c->status, 'Status' => Format::status($c), 'Health' => $c->health ?? '', 'Ports' => Format::ports($c, exposed: $this->docker->store->images[$c->imageId]->config->exposed ?? []), 'Publishers' => Format::ports($c), 'Command' => Format::command($c), 'Project' => $project->file->name, 'ID' => $c->shortId()];
            $render = static fn (array $values) => preg_replace_callback('/\{\{\s*\.(\w+)\s*\}\}/', static fn ($m) => (string) ($values[$m[1]] ?? ''), str_replace(['\\t', '\\n'], ["\t", "\n"], $template));
            if ($table) {
                $project->output .= strtoupper((string) $render(array_combine(array_keys($fields($containers[0] ?? new Container('x', 'x', 'x', 'x', [], [], '/', null, [], [], [], []))), array_map(static fn ($k) => strtoupper($k), array_keys($fields($containers[0] ?? new Container('x', 'x', 'x', 'x', [], [], '/', null, [], [], [], [])))))))."\n";
            }
            foreach ($containers as $c) {
                $project->output .= $render($fields($c))."\n";
            }

            return 0;
        }
        if (\in_array($args->get('format'), ['json', 'JSON'], true)) {
            foreach ($containers as $c) {
                $project->output .= json_encode([
                    'Name' => $c->name,
                    'Image' => preg_replace('/:latest$/', '', $c->imageRef) ?? $c->imageRef,
                    'Command' => implode(' ', $c->command),
                    'Project' => $project->file->name,
                    'Service' => (string) $c->composeService(),
                    'Created' => $c->createdAt,
                    'State' => $c->isRunning() ? 'running' : $c->status,
                    'Status' => Format::status($c),
                    'Health' => $c->health ?? '',
                    'Publishers' => Format::ports($c, exposed: $this->docker->store->images[$c->imageId]->config->exposed ?? []),
                ], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n";
            }

            return 0;
        }
        $rows = array_map(fn (Container $c) => [$c->name, preg_replace('/:latest$/', '', $c->imageRef) ?? $c->imageRef, Format::command($c), (string) $c->composeService(), Format::ago($c->createdAt), Format::status($c), Format::ports($c, exposed: $this->docker->store->images[$c->imageId]->config->exposed ?? [])], $containers);
        $project->output .= Format::table(['NAME', 'IMAGE', 'COMMAND', 'SERVICE', 'CREATED', 'STATUS', 'PORTS'], $rows);

        return 0;
    }

    private function logs(Project $project, array $argv): int
    {
        $args = Args::parse($argv, ['f' => ['follow', false], 'follow' => ['follow', false], 'tail' => ['tail', true], 'n' => ['tail', true], 'no-log-prefix' => ['no-prefix', false], 't' => ['timestamps', false], 'timestamps' => ['timestamps', false], 'since' => ['since', true]], 'compose logs');
        foreach ($args->positional as $service) {
            if (!isset($project->file->services[$service])) {
                return $project->fail(sprintf('no such service: %s', $service), 1);
            }
        }
        $project->output .= $project->logs($args->positional, $args->get('tail') !== null && $args->get('tail') !== 'all' ? (int) $args->get('tail') : null, $args->has('no-prefix'));

        return 0;
    }

    private function exec(Project $project, array $argv): int
    {
        $args = Args::parse($argv, ['T' => ['no-tty', false], 'no-TTY' => ['no-tty', false], 'u' => ['user', true], 'user' => ['user', true], 'w' => ['workdir', true], 'workdir' => ['workdir', true], 'e' => ['env', true, true], 'env' => ['env', true, true], 'd' => ['detach', false], 'i' => ['interactive', false], 't' => ['tty', false], 'index' => ['index', true], 'privileged' => ['privileged', false]], 'compose exec', stopAtPositional: true);
        if (\count($args->positional) < 2) {
            return $project->fail("\"docker compose exec\" requires at least 2 arguments.\nSee 'docker compose exec --help'.", 1);
        }
        $service = array_shift($args->positional);
        $env = [];
        foreach ($args->all('env') as $pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $env[$k] = $v;
        }
        $code = $project->exec($service, $args->positional, $args->get('user'), $args->get('workdir'), $env);
        if (\count($args->positional) === 1 && \in_array(basename($args->positional[0]), ['sh', 'bash', 'ash', 'psql', 'mysql', 'php'], true) && $code === 0) {
            $project->output .= sprintf("💡 La console du simulateur n'est pas un terminal : pas de session interactive. Passez la commande directement : docker compose exec %s sh -c \"ls -la\"\n", $service);
        }

        return $code;
    }

    private function runService(Project $project, array $argv): int
    {
        $args = Args::parse($argv, ['rm' => ['rm', false], 'no-deps' => ['no-deps', false], 'e' => ['env', true, true], 'env' => ['env', true, true], 'entrypoint' => ['entrypoint', true], 'u' => ['user', true], 'user' => ['user', true], 'T' => ['no-tty', false], 'd' => ['detach', false], 'i' => ['interactive', false], 't' => ['tty', false], 'name' => ['name', true], 'service-ports' => ['service-ports', false], 'build' => ['build', false], 'w' => ['workdir', true], 'P' => ['publish', false], 'p' => ['publish-list', true, true], 'v' => ['volume', true, true]], 'compose run', stopAtPositional: true);
        if ($args->positional === []) {
            return $project->fail("\"docker compose run\" requires at least 1 argument.\nSee 'docker compose run --help'.", 1);
        }
        $service = array_shift($args->positional);
        $env = [];
        foreach ($args->all('env') as $pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $env[$k] = $v;
        }

        return $project->run($service, $args->positional === [] ? null : $args->positional, $args->has('rm'), $args->has('no-deps'), $args->get('entrypoint'), $env, $args->get('user'));
    }

    private function build(Project $project, array $argv): int
    {
        $args = Args::parse($argv, ['no-cache' => ['no-cache', false], 'pull' => ['pull', false], 'q' => ['quiet', false], 'build-arg' => ['build-arg', true, true], 'progress' => ['progress', true]], 'compose build');
        foreach ($args->positional as $service) {
            if (!isset($project->file->services[$service])) {
                return $project->fail(sprintf('no such service: %s', $service), 1);
            }
        }

        return $project->build($args->positional, $args->has('no-cache')) ? 0 : 1;
    }

    private function config(Project $project, array $argv): int
    {
        if (\in_array('--services', $argv, true)) {
            $project->output .= implode("\n", array_keys($project->file->services))."\n";

            return 0;
        }
        if (\in_array('--volumes', $argv, true)) {
            $project->output .= implode("\n", array_keys($project->file->volumes))."\n";

            return 0;
        }
        if (\in_array('-q', $argv, true) || \in_array('--quiet', $argv, true)) {
            return 0;
        }
        $services = [];
        foreach ($project->file->services as $name => $service) {
            $entry = [];
            if (isset($service['build'])) {
                $entry['build'] = array_filter(['context' => $service['build']['context'], 'dockerfile' => $service['build']['dockerfile'] ?? 'Dockerfile', 'target' => $service['build']['target'], 'args' => $service['build']['args'] ?: null]);
            }
            foreach (['command', 'entrypoint'] as $key) {
                if (isset($service[$key])) {
                    $entry[$key] = $service[$key];
                }
            }
            if ($service['container_name'] !== null) {
                $entry['container_name'] = $service['container_name'];
            }
            if ($service['depends_on'] !== []) {
                $entry['depends_on'] = array_map(static fn ($d) => ['condition' => $d['condition'], 'required' => $d['required']], $service['depends_on']);
            }
            if ($service['environment'] !== []) {
                // Compose rend les variables par ordre alphabétique, pas dans l'ordre du fichier.
                $environment = $service['environment'];
                ksort($environment);
                $entry['environment'] = $environment;
            }
            if (isset($service['healthcheck'])) {
                $entry['healthcheck'] = ['test' => $service['healthcheck']['test'], 'interval' => $service['healthcheck']['interval'] ?? null, 'retries' => $service['healthcheck']['retries'] ?? null];
            }
            $entry['image'] = $project->imageName($name);
            $entry['networks'] = array_map(static fn () => null, $service['networks'] ?: ['default' => []]);
            if ($service['ports'] !== []) {
                $entry['ports'] = array_map(static fn ($p) => array_filter(['mode' => 'ingress', 'host_ip' => $p['ip'] !== '0.0.0.0' ? $p['ip'] : null, 'target' => $p['container'], 'published' => $p['host'] !== null ? (string) $p['host'] : null, 'protocol' => $p['protocol']]), $service['ports']);
            }
            if ($service['restart'] !== 'no') {
                $entry['restart'] = $service['restart'];
            }
            if ($service['volumes'] !== []) {
                $entry['volumes'] = array_map(static fn ($v) => array_filter(['type' => $v['type'], 'source' => $v['source'] ?: null, 'target' => $v['target'], 'read_only' => $v['readOnly'] ?: null]), $service['volumes']);
            }
            if ($service['working_dir'] !== null) {
                $entry['working_dir'] = $service['working_dir'];
            }
            if ($service['user'] !== null) {
                $entry['user'] = $service['user'];
            }
            ksort($entry);
            $services[$name] = $entry;
        }
        $document = ['name' => $project->file->name, 'services' => $services, 'networks' => array_map(fn ($n) => ['name' => $project->networkName($n)], array_combine(array_keys($project->file->networks ?: ['default' => []]), array_keys($project->file->networks ?: ['default' => []])))];
        if ($project->file->volumes !== []) {
            $document['volumes'] = array_map(fn ($v) => ['name' => $project->volumeName($v)], array_combine(array_keys($project->file->volumes), array_keys($project->file->volumes)));
        }
        $project->output .= self::inlineSequences(Yaml::dump($document, 10, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE));

        return 0;
    }

    /**
     * Remonte la première clé d'une table sur son tiret (« - mode: ingress »), comme Compose,
     * là où le dumper YAML laisse le tiret seul sur sa ligne.
     */
    private static function inlineSequences(string $yaml): string
    {
        $lines = explode("\n", $yaml);
        $result = [];
        for ($i = 0; $i < \count($lines); ++$i) {
            $line = $lines[$i];
            $next = $lines[$i + 1] ?? null;
            if ($next !== null && preg_match('/^(\s*)-$/', $line, $m) && str_starts_with($next, $m[1].'  ')) {
                $result[] = $m[1].'- '.ltrim($next);
                $indent = $m[1].'  ';
                for ($j = $i + 2; $j < \count($lines) && str_starts_with($lines[$j], $indent) && trim($lines[$j]) !== ''; ++$j) {
                    $result[] = $indent.ltrim($lines[$j]);
                }
                $i = $j - 1;
                continue;
            }
            $result[] = $line;
        }

        return implode("\n", $result);
    }

    private function pull(Project $project, array $argv): int
    {
        $pulled = [];
        foreach ($project->file->services as $name => $service) {
            if ($service['image'] !== null && !isset($service['build'])) {
                try {
                    $this->docker->pull($service['image']);
                    $pulled[] = $name;
                } catch (\Forelse\DockerSim\Catalog\UnknownImageException $e) {
                    return $project->fail(sprintf(" ✘ %s Error %s\nError response from daemon: %s", $name, $e->getMessage(), $e->getMessage()), 18);
                }
            }
        }
        $project->output .= sprintf("[+] Pulling %d/%d\n", \count($pulled), \count($pulled)).implode('', array_map(static fn ($n) => " ✔ {$n} Pulled\n", $pulled));

        return 0;
    }

    private function images(Project $project): int
    {
        $rows = [];
        foreach ($project->containers() as $container) {
            $image = $this->docker->store->images[$container->imageId] ?? null;
            if ($image === null) {
                continue;
            }
            $tag = $image->tags[0] ?? '<none>:<none>';
            $rows[] = [$container->name, substr($tag, 0, strrpos($tag, ':')), substr($tag, strrpos($tag, ':') + 1), 'linux/amd64', $image->shortId(), Format::size($image->size()), Format::ago($image->createdAt)];
        }
        $project->output .= Format::table(['CONTAINER', 'REPOSITORY', 'TAG', 'PLATFORM', 'IMAGE ID', 'SIZE', 'CREATED'], $rows);

        return 0;
    }

    private function top(Project $project): int
    {
        foreach ($project->containers() as $container) {
            if ($container->isRunning()) {
                $project->output .= $container->name."\n".Format::table(['UID', 'PID', 'PPID', 'C', 'STIME', 'TTY', 'TIME', 'CMD'], [[$container->user ?? ($container->process === 'postgres' ? '70' : 'root'), (string) (4000 + crc32($container->id) % 5000), (string) (3900 + crc32($container->id) % 5000), '0', '10:00', '?', '00:00:00', implode(' ', $container->processOptions['argv'] ?? $container->command)]])."\n";
            }
        }

        return 0;
    }

    private function rm(Project $project, array $argv): int
    {
        $removed = [];
        foreach ($project->containers() as $container) {
            if (!$container->isRunning()) {
                $this->docker->remove($container, true);
                $removed[] = $container->name;
            }
        }
        $project->output .= $removed === [] ? "No stopped containers\n" : "Going to remove ".implode(', ', $removed)."\n".implode('', array_map(static fn ($n) => " ✔ Container {$n}  Removed\n", $removed));

        return 0;
    }

    private function port(Project $project, array $argv): int
    {
        $container = $project->containers($argv[0] ?? '')[0] ?? null;
        foreach ($container?->ports ?? [] as $port) {
            if ((int) ($argv[1] ?? 0) === $port['container']) {
                $project->output .= $port['ip'].':'.$port['host']."\n";
            }
        }

        return 0;
    }

    private function ls(string &$output): int
    {
        $projects = [];
        foreach ($this->docker->store->containers as $container) {
            $name = $container->composeProject();
            if ($name !== null) {
                $projects[$name]['files'] = $container->labels['com.docker.compose.project.config_files'] ?? '';
                $projects[$name]['running'] = ($projects[$name]['running'] ?? 0) + ($container->isRunning() ? 1 : 0);
            }
        }
        $rows = array_map(static fn ($name, $p) => [$name, sprintf('running(%d)', $p['running']), $p['files']], array_keys($projects), $projects);
        $output .= Format::table(['NAME', 'STATUS', 'CONFIG FILES'], $rows);

        return 0;
    }
}
