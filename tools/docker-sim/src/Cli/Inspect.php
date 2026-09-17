<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Cli;

use Forelse\DockerSim\Engine\Docker;
use Forelse\DockerSim\State\Container;
use Forelse\DockerSim\State\Image;
use Forelse\DockerSim\State\Network;
use Forelse\DockerSim\State\Volume;

/** docker inspect : la structure JSON de l'API Docker (les champs utiles), et --format "{{…}}". */
final class Inspect
{
    /** @return array<string,mixed> */
    public static function container(Docker $docker, Container $container): array
    {
        $ports = [];
        foreach ($container->ports as $port) {
            $ports[$port['container'].'/'.$port['protocol']][] = ['HostIp' => $port['ip'], 'HostPort' => (string) $port['host']];
        }
        $networks = [];
        foreach ($container->networks as $name => $aliases) {
            $networks[$name] = ['Aliases' => $aliases === [] ? null : $aliases, 'NetworkID' => $docker->store->networks[$name]->id ?? '', 'IPAddress' => $container->ips[$name] ?? '', 'Gateway' => preg_replace('/\.\d+$/', '.1', $container->ips[$name] ?? '') ?? '', 'DNSNames' => array_values(array_unique([$container->name, ...$aliases, substr($container->id, 0, 12)]))];
        }
        $image = $docker->store->images[$container->imageId] ?? null;

        return [
            'Id' => $container->id,
            'Created' => gmdate('Y-m-d\TH:i:s.u\Z', $container->createdAt),
            'Path' => $container->command[0] ?? '',
            'Args' => \array_slice($container->command, 1),
            'State' => [
                'Status' => $container->status,
                'Running' => $container->isRunning(),
                'Restarting' => $container->status === Container::RESTARTING,
                'ExitCode' => $container->exitCode,
                'Error' => $container->error ?? '',
                'StartedAt' => $container->startedAt ? gmdate('Y-m-d\TH:i:s.u\Z', $container->startedAt) : '0001-01-01T00:00:00Z',
                'FinishedAt' => $container->finishedAt ? gmdate('Y-m-d\TH:i:s.u\Z', $container->finishedAt) : '0001-01-01T00:00:00Z',
            ] + ($container->health !== null ? ['Health' => ['Status' => $container->health, 'FailingStreak' => (int) ($container->processOptions['failingStreak'] ?? ($container->health === 'unhealthy' ? 3 : 0)), 'Log' => $container->processOptions['healthRuns'] ?? [['ExitCode' => $container->health === 'healthy' ? 0 : 1, 'Output' => $container->healthLog]]]] : []),
            'Image' => $container->imageId,
            'Name' => '/'.$container->name,
            'RestartCount' => $container->restartCount,
            'HostConfig' => [
                'Binds' => array_values(array_map(static fn ($m) => $m['source'].':'.$m['target'].($m['readOnly'] ? ':ro' : ''), array_filter($container->mounts, static fn ($m) => $m['type'] === 'bind'))) ?: null,
                'NetworkMode' => array_key_first($container->networks) ?? 'bridge',
                'PortBindings' => $ports,
                'RestartPolicy' => ['Name' => $container->restart, 'MaximumRetryCount' => 0],
                'AutoRemove' => $container->autoRemove,
            ],
            'Mounts' => array_map(static fn ($m) => ['Type' => $m['type'], 'Name' => $m['type'] === 'volume' ? $m['source'] : null, 'Source' => $m['type'] === 'volume' ? '/var/lib/docker/volumes/'.$m['source'].'/_data' : $m['source'], 'Destination' => $m['target'], 'Driver' => $m['type'] === 'volume' ? 'local' : '', 'Mode' => $m['readOnly'] ? 'ro' : '', 'RW' => !$m['readOnly'], 'Propagation' => $m['type'] === 'bind' ? 'rprivate' : ''], $container->mounts),
            'Config' => [
                'Hostname' => $container->hostname,
                'User' => $container->user ?? '',
                'ExposedPorts' => $image !== null ? array_fill_keys($image->config->exposed, new \stdClass()) : [],
                'Env' => array_map(static fn ($k, $v) => $k.'='.$v, array_keys($container->env), $container->env),
                'Cmd' => $container->command,
                'Healthcheck' => $container->healthcheck !== null ? self::healthcheck($container->healthcheck) : null,
                'Image' => $container->imageRef,
                'WorkingDir' => $container->workdir,
                'Labels' => $container->labels === [] ? new \stdClass() : $container->labels,
            ],
            'NetworkSettings' => ['Ports' => $ports, 'Networks' => $networks, 'IPAddress' => $container->ips['bridge'] ?? ''],
        ];
    }

    /** @return array<string,mixed> */
    public static function image(Image $image): array
    {
        return [
            'Id' => $image->id,
            'RepoTags' => $image->tags,
            'Created' => gmdate('Y-m-d\TH:i:s.u\Z', $image->createdAt),
            'Config' => [
                'User' => $image->config->user ?? '',
                'ExposedPorts' => $image->config->exposed === [] ? null : array_fill_keys($image->config->exposed, new \stdClass()),
                'Env' => array_map(static fn ($k, $v) => $k.'='.$v, array_keys($image->config->env), $image->config->env),
                'Cmd' => $image->config->cmd,
                'Healthcheck' => $image->config->healthcheck !== null ? self::healthcheck($image->config->healthcheck) : null,
                'WorkingDir' => $image->config->workdir === '/' ? '' : $image->config->workdir,
                'Entrypoint' => $image->config->entrypoint,
                'Volumes' => $image->config->volumes === [] ? null : array_fill_keys($image->config->volumes, new \stdClass()),
                'Labels' => $image->config->labels === [] ? null : $image->config->labels,
                'StopSignal' => $image->config->stopSignal,
            ],
            'Architecture' => 'amd64',
            'Os' => 'linux',
            'Size' => $image->size(),
            'RootFS' => ['Type' => 'layers', 'Layers' => array_values(array_map(static fn ($l) => $l->id, array_filter($image->layers, static fn ($l) => !$l->empty)))],
        ];
    }

    /** @return array<string,mixed> */
    public static function network(Docker $docker, Network $network): array
    {
        $containers = [];
        foreach ($docker->store->containers as $container) {
            if (isset($container->networks[$network->name]) && $container->isRunning()) {
                $containers[$container->id] = ['Name' => $container->name, 'IPv4Address' => ($container->ips[$network->name] ?? '').'/16'];
            }
        }

        return ['Name' => $network->name, 'Id' => $network->id, 'Created' => gmdate('Y-m-d\TH:i:s.u\Z', $network->createdAt), 'Scope' => 'local', 'Driver' => $network->driver, 'IPAM' => ['Config' => $network->subnet !== '' ? [['Subnet' => $network->subnet, 'Gateway' => preg_replace('#\.0/\d+$#', '.1', $network->subnet)]] : []], 'Containers' => $containers === [] ? new \stdClass() : $containers, 'Labels' => $network->labels === [] ? new \stdClass() : $network->labels];
    }

    /** @return array<string,mixed> */
    public static function volume(Docker $docker, Volume $volume): array
    {
        return ['CreatedAt' => gmdate('Y-m-d\TH:i:s\Z', $volume->createdAt), 'Driver' => 'local', 'Labels' => $volume->labels === [] ? null : $volume->labels, 'Mountpoint' => '/var/lib/docker/volumes/'.$volume->name.'/_data', 'Name' => $volume->name, 'Options' => null, 'Scope' => 'local'];
    }

    /** --format "{{.State.Status}}", "{{json .Config.Env}}", "{{range .Mounts}}{{.Destination}} {{end}}" (sous-ensemble). */
    public static function format(string $template, array $data): string
    {
        $template = preg_replace_callback('/\{\{\s*range\s+(\.[\w.]+)\s*\}\}(.*?)\{\{\s*end\s*\}\}/s', static function ($m) use ($data) {
            $items = self::path($data, $m[1]);
            $out = '';
            foreach (\is_array($items) ? $items : [] as $key => $item) {
                $out .= self::format($m[2], \is_array($item) ? $item : ['.' => $item]);
            }

            return $out;
        }, $template) ?? $template;

        return preg_replace_callback('/\{\{\s*(json\s+)?(\.[\w.]*)\s*\}\}/', static function ($m) use ($data) {
            $value = $m[2] === '.' ? ($data['.'] ?? $data) : self::path($data, $m[2]);
            if ($m[1] !== '') {
                return json_encode($value, \JSON_UNESCAPED_SLASHES);
            }
            if (\is_bool($value)) {
                return $value ? 'true' : 'false';
            }
            if (\is_array($value)) {
                return array_is_list($value) ? '['.implode(' ', array_map(static fn ($v) => \is_scalar($v) ? (string) $v : json_encode($v), $value)).']' : 'map['.implode(' ', array_map(static fn ($k, $v) => $k.':'.(\is_scalar($v) ? $v : json_encode($v)), array_keys($value), $value)).']';
            }

            return $value === null ? '<no value>' : (string) (\is_object($value) ? '{}' : $value);
        }, $template) ?? $template;
    }

    private static function path(array $data, string $path): mixed
    {
        $value = $data;
        foreach (array_filter(explode('.', $path)) as $segment) {
            if (!\is_array($value) || !\array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Comme docker inspect : les durées en nanosecondes, seulement celles qui sont déclarées.
     *
     * @param array{test: list<string>, interval?: string, timeout?: string, startPeriod?: string, retries?: int} $healthcheck
     */
    private static function healthcheck(array $healthcheck): array
    {
        $nanoseconds = static function (string $duration): int {
            preg_match_all('/(\d+(?:\.\d+)?)(ms|s|m|h)/', $duration, $parts, \PREG_SET_ORDER);

            return (int) array_sum(array_map(static fn ($p) => (float) $p[1] * ['ms' => 1e6, 's' => 1e9, 'm' => 6e10, 'h' => 3.6e12][$p[2]], $parts));
        };

        return array_filter([
            'Test' => $healthcheck['test'],
            'Interval' => isset($healthcheck['interval']) ? $nanoseconds((string) $healthcheck['interval']) : null,
            'Timeout' => isset($healthcheck['timeout']) ? $nanoseconds((string) $healthcheck['timeout']) : null,
            'StartPeriod' => isset($healthcheck['startPeriod']) ? $nanoseconds((string) $healthcheck['startPeriod']) : null,
            'Retries' => $healthcheck['retries'] ?? null,
        ], static fn ($v) => $v !== null);
    }
}
