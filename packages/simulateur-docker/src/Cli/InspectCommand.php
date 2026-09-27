<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Cli;

use Forelse\DockerSim\Engine\Docker;

/** docker inspect, et les « inspect » des sous-commandes (image, container, network, volume). */
final class InspectCommand implements CliCommand
{
    public function __construct(private readonly Docker $docker)
    {
    }

    public function names(): array
    {
        return ['inspect'];
    }

    public function run(string $name, array $argv, Output $out): int
    {
        return match ($name) {
            'inspect' => $this->inspect($argv, null, $out),
            default => throw new \LogicException(sprintf('%s : « %s » est déclarée sans implémentation.', self::class, $name)),
        };
    }

    /**
     * @param list<string> $argv
     * @param ?string      $type container, image, network ou volume ; null : le premier objet qui porte ce nom
     */
    public function inspect(array $argv, ?string $type, Output $out): int
    {
        $args = Args::parse($argv, ['f' => ['format', true], 'format' => ['format', true], 'type' => ['type', true], 's' => ['size', false]], 'inspect');
        $type ??= $args->get('type');
        $results = [];
        foreach ($args->positional as $name) {
            $object = null;
            if ($type === null || $type === 'container') {
                $container = $this->docker->findContainer($name);
                if ($container !== null) {
                    $this->docker->refreshHealth($container);
                }
                $object = $container !== null ? Inspect::container($this->docker, $container) : null;
            }
            if ($object === null && ($type === null || $type === 'image')) {
                $image = $this->docker->findImage($name);
                $object = $image !== null ? Inspect::image($image) : null;
            }
            if ($object === null && ($type === null || $type === 'network') && isset($this->docker->networks()[$name])) {
                $object = Inspect::network($this->docker, $this->docker->networks()[$name]);
            }
            if ($object === null && ($type === null || $type === 'volume') && isset($this->docker->volumes()[$name])) {
                $object = Inspect::volume($this->docker, $this->docker->volumes()[$name]);
            }
            if ($object === null) {
                $out->line(sprintf('[]\nError: No such object: %s', $name));

                return 1;
            }
            $results[] = $object;
        }
        if ($args->get('format') !== null) {
            foreach ($results as $result) {
                $out->line(Inspect::format((string) $args->get('format'), $result));
            }

            return 0;
        }
        $out->line(json_encode($results, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));

        return 0;
    }
}
