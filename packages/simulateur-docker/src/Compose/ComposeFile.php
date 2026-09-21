<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Compose;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Le fichier compose, lu comme Docker Compose v2 : interpolation des variables (.env), validation
 * (clés inconnues, services, volumes et réseaux non déclarés), normalisation des formes courtes
 * (ports, volumes, environment, depends_on…).
 */
final class ComposeFile
{
    public const FILES = ['compose.yaml', 'compose.yml', 'docker-compose.yaml', 'docker-compose.yml'];
    public const OVERRIDES = ['compose.override.yaml', 'compose.override.yml', 'docker-compose.override.yaml', 'docker-compose.override.yml'];

    private const TOP_LEVEL = ['version', 'name', 'services', 'volumes', 'networks', 'secrets', 'configs', 'include'];
    private const SERVICE_KEYS = ['image', 'build', 'command', 'entrypoint', 'environment', 'env_file', 'ports', 'expose', 'volumes', 'depends_on', 'healthcheck', 'restart', 'container_name', 'working_dir', 'user', 'networks', 'hostname', 'labels', 'profiles', 'tty', 'stdin_open', 'extra_hosts', 'init', 'stop_grace_period', 'stop_signal', 'deploy', 'develop', 'secrets', 'configs', 'platform', 'pull_policy', 'logging', 'links', 'cap_add', 'cap_drop', 'privileged', 'read_only', 'shm_size', 'ulimits', 'sysctls', 'security_opt', 'dns', 'extends', 'domainname', 'mem_limit', 'cpus', 'x-develop'];

    /** @var list<string> */
    public array $warnings = [];
    /** @var array<string, array<string,mixed>> */
    public array $services = [];
    /** @var array<string, array<string,mixed>> */
    public array $volumes = [];
    /** @var array<string, array<string,mixed>> */
    public array $networks = [];
    public string $name = '';
    public string $path = '';
    /** @var list<string> les fichiers de surcharge appliqués après $path */
    public array $paths = [];

    /** @param array<string,string> $environment variables pour l'interpolation (en plus du .env) */
    /**
     * @param string|list<string>|null $file un fichier, ou plusieurs (-f a.yaml -f b.yaml) fusionnés dans l'ordre
     */
    public static function load(string $projectDirectory, string|array|null $file = null, ?string $projectName = null, array $environment = [], ?string $envFile = null): self
    {
        $compose = new self();
        try {
            return self::build($compose, $projectDirectory, $file, $projectName, $environment, $envFile);
        } catch (ComposeException $e) {
            // Les avertissements déjà relevés accompagnent l'erreur : Compose les affiche avant elle.
            throw $e->withWarnings($compose->warnings);
        }
    }

    private static function build(self $compose, string $projectDirectory, string|array|null $file = null, ?string $projectName = null, array $environment = [], ?string $envFile = null): self
    {
        $projectDirectory = rtrim($projectDirectory, '/');
        $extra = [];
        if (\is_array($file)) {
            $files = array_values($file);
            $file = array_shift($files) ?: null;
            foreach ($files as $additional) {
                $extra[] = str_starts_with($additional, '/') ? $additional : $projectDirectory.'/'.$additional;
            }
        }
        if ($file !== null) {
            $path = str_starts_with($file, '/') ? $file : $projectDirectory.'/'.$file;
            if (!is_file($path)) {
                throw new ComposeException(sprintf('open %s: no such file or directory', $path));
            }
        } else {
            $path = null;
            foreach (self::FILES as $candidate) {
                if (is_file($projectDirectory.'/'.$candidate)) {
                    $path = $projectDirectory.'/'.$candidate;
                    break;
                }
            }
            if ($path === null) {
                throw new ComposeException('no configuration file provided: not found');
            }
            if (str_starts_with(basename($path), 'docker-compose') && is_file($projectDirectory.'/compose.yaml')) {
                $compose->warnings[] = sprintf('Found multiple config files with supported names: %s/compose.yaml, %s', $projectDirectory, $path);
            }
            // Sans -f, Compose ajoute de lui-même le fichier de surcharge s'il existe.
            foreach (self::OVERRIDES as $candidate) {
                if (is_file($projectDirectory.'/'.$candidate)) {
                    $extra[] = $projectDirectory.'/'.$candidate;
                    break;
                }
            }
        }
        $compose->path = $path;
        if ($envFile !== null) {
            $envPath = str_starts_with($envFile, '/') ? $envFile : $projectDirectory.'/'.$envFile;
            if (!is_file($envPath)) {
                throw new ComposeException(sprintf('env file %s not found: stat %s: no such file or directory', $envPath, $envPath));
            }
        } else {
            $envPath = $projectDirectory.'/.env';
        }
        $variables = $environment + EnvFile::load($envPath);

        $data = self::parse($path);
        foreach ($extra as $additional) {
            if (!is_file($additional)) {
                throw new ComposeException(sprintf('open %s: no such file or directory', $additional));
            }
            $surcharge = self::parse($additional);
            if (\is_array($data) && \is_array($surcharge)) {
                $data = self::merge($data, $surcharge);
            }
            $compose->paths[] = $additional;
        }
        if ($data === null) {
            throw new ComposeException(sprintf('empty compose file: %s', $path));
        }
        if (!\is_array($data)) {
            throw new ComposeException(sprintf('validating %s: (root) must be a mapping', $path));
        }
        // Relevé avant toute validation : Compose prévient pour « version » même si le fichier est invalide.
        if (isset($data['version'])) {
            $compose->warnings[] = sprintf('%s: the attribute `version` is obsolete, it will be ignored, please remove it to avoid potential confusion', $path);
        }
        foreach (array_keys($data) as $key) {
            if (!\in_array($key, self::TOP_LEVEL, true) && !str_starts_with((string) $key, 'x-')) {
                throw new ComposeException(sprintf("validating %s: (root) additional properties '%s' not allowed", $path, $key));
            }
        }
        $data = $compose->interpolate($data, $variables, '');
        $compose->name = self::projectName($projectName ?? $data['name'] ?? ($variables['COMPOSE_PROJECT_NAME'] ?? basename($projectDirectory)));
        if (!\is_array($data['services'] ?? null) || $data['services'] === []) {
            if (!isset($data['services'])) {
                throw new ComposeException('no service selected');
            }
            throw new ComposeException(sprintf('validating %s: services must be a mapping', $path));
        }
        foreach ($data['volumes'] ?? [] as $name => $config) {
            $compose->volumes[(string) $name] = \is_array($config) ? $config : [];
        }
        foreach ($data['networks'] ?? [] as $name => $config) {
            $compose->networks[(string) $name] = \is_array($config) ? $config : [];
        }
        foreach ($data['services'] as $name => $service) {
            $compose->services[(string) $name] = $compose->normalizeService((string) $name, $service, $projectDirectory, $path);
        }
        $compose->checkReferences();

        return $compose;
    }

    /** @return array<string,mixed>|null */
    private static function parse(string $path): ?array
    {
        try {
            $data = Yaml::parse((string) file_get_contents($path));
        } catch (ParseException $e) {
            throw new ComposeException(sprintf('yaml: line %d: %s', max(1, $e->getParsedLine()), lcfirst(preg_replace('/ at line \d+.*$/s', '', $e->getRawMessage()) ?? $e->getRawMessage())));
        }

        return \is_array($data) ? $data : ($data === null ? null : throw new ComposeException(sprintf('validating %s: (root) must be a mapping', $path)));
    }

    /**
     * Fusion à la manière de Compose : les tableaux associatifs se complètent, les listes
     * (ports, volumes, depends_on…) s'ajoutent, et une valeur simple remplace la précédente.
     *
     * @param array<string,mixed> $base
     * @param array<string,mixed> $surcharge
     *
     * @return array<string,mixed>
     */
    public static function merge(array $base, array $surcharge): array
    {
        foreach ($surcharge as $cle => $valeur) {
            if (!\array_key_exists($cle, $base)) {
                $base[$cle] = $valeur;
                continue;
            }
            $actuel = $base[$cle];
            if (\is_array($actuel) && \is_array($valeur)) {
                if (array_is_list($actuel) && array_is_list($valeur)) {
                    // command et entrypoint sont remplacés, pas complétés.
                    $base[$cle] = \in_array($cle, ['command', 'entrypoint', 'test'], true) ? $valeur : array_values(array_unique([...$actuel, ...$valeur], \SORT_REGULAR));
                    continue;
                }
                $base[$cle] = self::merge(array_is_list($actuel) ? [] : $actuel, array_is_list($valeur) ? [] : $valeur) + (array_is_list($valeur) ? $valeur : []);
                continue;
            }
            $base[$cle] = $valeur;
        }

        return $base;
    }

    public static function projectName(string $name): string
    {
        $name = strtolower($name);
        $name = preg_replace('/[^a-z0-9_-]/', '', $name) ?? $name;

        return ltrim($name, '_-') ?: 'default';
    }

    /** @param array<string,string> $variables */
    private function interpolate(mixed $value, array $variables, string $path): mixed
    {
        if (\is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = $this->interpolate($item, $variables, $path === '' ? (string) $key : $path.'.'.$key);
            }

            return $result;
        }
        if (!\is_string($value)) {
            return $value;
        }

        return preg_replace_callback('/\$\$|\$\{([A-Za-z_][A-Za-z0-9_]*)(?:(:?[-?+])((?:[^{}]|\{[^}]*\})*))?\}|\$([A-Za-z_][A-Za-z0-9_]*)/', function (array $m) use ($variables, $path): string {
            if ($m[0] === '$$') {
                return '$';
            }
            $name = ($m[1] ?? '') !== '' ? $m[1] : ($m[4] ?? '');
            $op = $m[2] ?? '';
            $word = $m[3] ?? '';
            $set = \array_key_exists($name, $variables);
            $current = $variables[$name] ?? '';
            switch ($op) {
                case ':-':
                    return $current === '' ? $this->interpolate($word, $variables, $path) : $current;
                case '-':
                    return $set ? $current : $this->interpolate($word, $variables, $path);
                case ':?':
                case '?':
                    if ($op === ':?' ? $current === '' : !$set) {
                        throw new ComposeException(sprintf('error while interpolating %s: required variable %s is missing a value%s', $path, $name, $word !== '' ? ': '.$word : ''));
                    }

                    return $current;
                case ':+':
                    return $current !== '' ? $word : '';
                case '+':
                    return $set ? $word : '';
            }
            if (!$set) {
                $warning = sprintf('The "%s" variable is not set. Defaulting to a blank string.', $name);
                if (!\in_array($warning, $this->warnings, true)) {
                    $this->warnings[] = $warning;
                }
            }

            return $current;
        }, $value) ?? $value;
    }

    /** @return array<string,mixed> */
    private function normalizeService(string $name, mixed $service, string $projectDirectory, string $path): array
    {
        if (!\is_array($service)) {
            throw new ComposeException(sprintf('validating %s: services.%s must be a mapping', $path, $name));
        }
        foreach (array_keys($service) as $key) {
            if (!\in_array($key, self::SERVICE_KEYS, true) && !str_starts_with((string) $key, 'x-')) {
                throw new ComposeException(sprintf("validating %s: services.%s additional properties '%s' not allowed", $path, $name, $key));
            }
        }
        if (!isset($service['image']) && !isset($service['build'])) {
            throw new ComposeException(sprintf('service "%s" has neither an image nor a build context specified: invalid compose project', $name));
        }
        $normalized = ['name' => $name];
        $normalized['image'] = isset($service['image']) ? (string) $service['image'] : null;
        if (isset($service['build'])) {
            $build = \is_string($service['build']) ? ['context' => $service['build']] : $service['build'];
            if (!\is_array($build)) {
                throw new ComposeException(sprintf('validating %s: services.%s.build must be a string or a mapping', $path, $name));
            }
            $context = (string) ($build['context'] ?? '.');
            $normalized['build'] = [
                'context' => str_starts_with($context, '/') ? $context : (rtrim($projectDirectory.'/'.preg_replace('#^\./?#', '', $context), '/') ?: $projectDirectory),
                'dockerfile' => $build['dockerfile'] ?? null,
                'target' => $build['target'] ?? null,
                'args' => $this->keyValues($build['args'] ?? [], $path, $name.'.build.args'),
            ];
            if (str_ends_with((string) $normalized['build']['context'], '/.') || $context === '.') {
                $normalized['build']['context'] = $projectDirectory;
            }
        }
        foreach (['command', 'entrypoint'] as $key) {
            if (\array_key_exists($key, $service)) {
                $value = $service[$key];
                $normalized[$key] = $value === null ? null : (\is_array($value) ? array_map('strval', $value) : self::shellSplit((string) $value));
            }
        }
        $environment = [];
        foreach ((array) ($service['env_file'] ?? []) as $envFile) {
            $file = \is_array($envFile) ? ($envFile['path'] ?? '') : (string) $envFile;
            $required = !\is_array($envFile) || ($envFile['required'] ?? true);
            $absolute = str_starts_with($file, '/') ? $file : $projectDirectory.'/'.ltrim($file, './');
            if (!is_file($absolute)) {
                if ($required) {
                    throw new ComposeException(sprintf('env file %s not found: stat %s: no such file or directory', $absolute, $absolute));
                }
                continue;
            }
            $environment = EnvFile::load($absolute) + $environment;
            foreach (EnvFile::load($absolute) as $key => $value) {
                $environment[$key] = $value;
            }
        }
        foreach ($this->keyValues($service['environment'] ?? [], $path, $name.'.environment') as $key => $value) {
            if ($value !== null) {
                $environment[$key] = $value;
            }
        }
        $normalized['environment'] = $environment;
        $normalized['ports'] = [];
        if (isset($service['ports'])) {
            if (!\is_array($service['ports'])) {
                throw new ComposeException(sprintf('validating %s: services.%s.ports must be a array', $path, $name));
            }
            foreach ($service['ports'] as $port) {
                array_push($normalized['ports'], ...$this->port($port, $name, $path));
            }
        }
        $normalized['volumes'] = [];
        foreach ((array) ($service['volumes'] ?? []) as $volume) {
            $normalized['volumes'][] = $this->volume($volume, $projectDirectory, $name, $path);
        }
        $normalized['depends_on'] = [];
        if (isset($service['depends_on'])) {
            foreach ($service['depends_on'] as $key => $value) {
                if (\is_int($key)) {
                    $normalized['depends_on'][(string) $value] = ['condition' => 'service_started', 'required' => true];
                } else {
                    $condition = \is_array($value) ? ($value['condition'] ?? 'service_started') : 'service_started';
                    if (!\in_array($condition, ['service_started', 'service_healthy', 'service_completed_successfully'], true)) {
                        throw new ComposeException(sprintf("validating %s: services.%s.depends_on.%s.condition must be one of the following: \"service_started\", \"service_healthy\", \"service_completed_successfully\"", $path, $name, $key));
                    }
                    $normalized['depends_on'][(string) $key] = ['condition' => $condition, 'required' => \is_array($value) ? ($value['required'] ?? true) : true];
                }
            }
        }
        if (isset($service['healthcheck'])) {
            $health = $service['healthcheck'];
            if (($health['disable'] ?? false) === true) {
                $normalized['healthcheck'] = ['test' => ['NONE']];
            } else {
                $test = $health['test'] ?? null;
                $normalized['healthcheck'] = [
                    'test' => \is_string($test) ? ['CMD-SHELL', $test] : array_map('strval', (array) $test),
                    'interval' => $health['interval'] ?? '30s',
                    'timeout' => $health['timeout'] ?? '30s',
                    'retries' => (int) ($health['retries'] ?? 3),
                    'startPeriod' => $health['start_period'] ?? '0s',
                ];
                if (!\in_array($normalized['healthcheck']['test'][0] ?? '', ['CMD', 'CMD-SHELL', 'NONE'], true)) {
                    // Liste sans CMD/CMD-SHELL : Compose l'exécute directement.
                    array_unshift($normalized['healthcheck']['test'], 'CMD');
                }
            }
        }
        $normalized['restart'] = (string) ($service['restart'] ?? 'no');
        if (!preg_match('/^(no|always|unless-stopped|on-failure(:\d+)?)$/', $normalized['restart'])) {
            throw new ComposeException(sprintf('invalid restart policy: unknown policy \'%s\'; use one of \'no\', \'always\', \'on-failure\', or \'unless-stopped\'', $normalized['restart']));
        }
        foreach (['container_name', 'working_dir', 'user', 'hostname'] as $key) {
            $normalized[$key] = isset($service[$key]) ? (string) $service[$key] : null;
        }
        $normalized['networks'] = [];
        if (isset($service['networks'])) {
            foreach ($service['networks'] as $key => $value) {
                if (\is_int($key)) {
                    $normalized['networks'][(string) $value] = [];
                } else {
                    $normalized['networks'][(string) $key] = array_map('strval', \is_array($value) ? ($value['aliases'] ?? []) : []);
                }
            }
        }
        $normalized['labels'] = array_map('strval', array_filter($this->keyValues($service['labels'] ?? [], $path, $name.'.labels'), static fn ($v) => $v !== null));
        $normalized['profiles'] = array_map('strval', (array) ($service['profiles'] ?? []));
        $normalized['tty'] = (bool) ($service['tty'] ?? false);
        $normalized['stdin_open'] = (bool) ($service['stdin_open'] ?? false);
        foreach (['secrets', 'configs', 'develop', 'deploy'] as $ignored) {
            if (isset($service[$ignored])) {
                $normalized['ignored'][] = $ignored;
            }
        }

        return $normalized;
    }

    /** @return array<string,?string> */
    private function keyValues(mixed $value, string $path, string $where): array
    {
        if ($value === null || $value === []) {
            return [];
        }
        if (!\is_array($value)) {
            throw new ComposeException(sprintf('validating %s: services.%s must be a mapping or a list', $path, $where));
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (\is_int($key)) {
                $item = (string) $item;
                if (str_contains($item, '=')) {
                    [$k, $v] = explode('=', $item, 2);
                    $result[$k] = $v;
                } else {
                    $result[$item] = null;
                }
            } else {
                $result[(string) $key] = $item === null ? null : (\is_bool($item) ? ($item ? 'true' : 'false') : (string) $item);
            }
        }

        return $result;
    }

    /** @return list<array{host: ?int, container: int, protocol: string, ip: string}> */
    private function port(mixed $port, string $service, string $path): array
    {
        if (\is_array($port)) {
            return [['host' => isset($port['published']) ? (int) $port['published'] : null, 'container' => (int) ($port['target'] ?? 0), 'protocol' => (string) ($port['protocol'] ?? 'tcp'), 'ip' => (string) ($port['host_ip'] ?? '0.0.0.0')]];
        }
        if (\is_int($port)) {
            // YAML lit 80:80 en base 60 dans certains cas : Compose recommande les guillemets.
            return [['host' => null, 'container' => $port, 'protocol' => 'tcp', 'ip' => '0.0.0.0']];
        }
        $spec = (string) $port;
        $protocol = 'tcp';
        if (str_contains($spec, '/')) {
            [$spec, $protocol] = explode('/', $spec, 2);
        }
        $parts = explode(':', $spec);
        $ip = '0.0.0.0';
        if (\count($parts) === 3) {
            $ip = array_shift($parts);
        }
        if (\count($parts) === 1) {
            return [['host' => null, 'container' => (int) $parts[0], 'protocol' => $protocol, 'ip' => $ip]];
        }
        [$host, $container] = $parts;
        if (!ctype_digit(str_replace('-', '', $host)) || !ctype_digit(str_replace('-', '', $container))) {
            throw new ComposeException(sprintf('invalid containerPort: %s', $container));
        }

        return [['host' => (int) $host, 'container' => (int) $container, 'protocol' => $protocol, 'ip' => $ip]];
    }

    /** @return array{type: string, source: string, target: string, readOnly: bool} */
    private function volume(mixed $volume, string $projectDirectory, string $service, string $path): array
    {
        if (\is_array($volume)) {
            $type = (string) ($volume['type'] ?? 'volume');
            $source = (string) ($volume['source'] ?? '');
            if ($type === 'bind' && !str_starts_with($source, '/')) {
                $source = rtrim($projectDirectory.'/'.preg_replace('#^\./#', '', $source), '/');
            }
            if ($type === 'bind' && !file_exists($source) && ($volume['bind']['create_host_path'] ?? false) !== true) {
                throw new ComposeException(sprintf('Error response from daemon: invalid mount config for type "bind": bind source path does not exist: %s', $source));
            }

            return ['type' => $type === 'bind' ? 'bind' : 'volume', 'source' => $source, 'target' => (string) ($volume['target'] ?? ''), 'readOnly' => (bool) ($volume['read_only'] ?? false)];
        }
        $parts = explode(':', (string) $volume);
        if (\count($parts) === 1) {
            return ['type' => 'volume', 'source' => '', 'target' => $parts[0], 'readOnly' => false];
        }
        $source = $parts[0];
        $target = $parts[1];
        $readOnly = \in_array('ro', explode(',', $parts[2] ?? ''), true);
        if (str_starts_with($source, '.') || str_starts_with($source, '/') || str_starts_with($source, '~')) {
            $absolute = str_starts_with($source, '/') ? $source : rtrim($projectDirectory.'/'.preg_replace('#^\./?#', '', $source), '/');

            return ['type' => 'bind', 'source' => $absolute === '' ? $projectDirectory : $absolute, 'target' => $target, 'readOnly' => $readOnly];
        }

        return ['type' => 'volume', 'source' => $source, 'target' => $target, 'readOnly' => $readOnly];
    }

    private function checkReferences(): void
    {
        foreach ($this->services as $name => $service) {
            foreach (array_keys($service['depends_on']) as $dependency) {
                if (!isset($this->services[$dependency])) {
                    throw new ComposeException(sprintf('service "%s" depends on undefined service "%s": invalid compose project', $name, $dependency));
                }
            }
            foreach ($service['volumes'] as $volume) {
                if ($volume['type'] === 'volume' && $volume['source'] !== '' && !isset($this->volumes[$volume['source']])) {
                    throw new ComposeException(sprintf('service "%s" refers to undefined volume %s: invalid compose project', $name, $volume['source']));
                }
            }
            foreach (array_keys($service['networks']) as $network) {
                if ($network !== 'default' && !isset($this->networks[$network])) {
                    throw new ComposeException(sprintf('service "%s" refers to undefined network %s: invalid compose project', $name, $network));
                }
            }
        }
        $this->sortedServices();
    }

    /**
     * Services dans l'ordre de démarrage (dépendances d'abord).
     *
     * @param list<string>|null $only
     *
     * @return list<string>
     */
    public function sortedServices(?array $only = null, bool $withDependencies = true): array
    {
        $order = [];
        $visiting = [];
        $visit = function (string $name, array $stack) use (&$visit, &$order, &$visiting, $withDependencies): void {
            if (\in_array($name, $order, true)) {
                return;
            }
            if (isset($visiting[$name])) {
                throw new ComposeException(sprintf('dependency cycle detected: %s', implode(' -> ', [...$stack, $name])));
            }
            $visiting[$name] = true;
            if ($withDependencies) {
                foreach (array_keys($this->services[$name]['depends_on']) as $dependency) {
                    $visit($dependency, [...$stack, $name]);
                }
            }
            unset($visiting[$name]);
            $order[] = $name;
        };
        foreach ($only ?? array_keys($this->services) as $name) {
            if (!isset($this->services[$name])) {
                throw new ComposeException(sprintf('no such service: %s', $name));
            }
            $visit($name, []);
        }

        return $order;
    }

    /** Découpe une commande comme shlex (Compose ne passe pas par /bin/sh). @return list<string> */
    public static function shellSplit(string $command): array
    {
        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|\'([^\']*)\'|(\S+)/', $command, $matches, PREG_SET_ORDER);

        return array_map(static function (array $m): string {
            if (isset($m[3]) && $m[3] !== '') {
                return $m[3];
            }
            if (isset($m[2]) && $m[2] !== '') {
                return $m[2];
            }

            return stripcslashes($m[1] ?? '');
        }, $matches);
    }
}
