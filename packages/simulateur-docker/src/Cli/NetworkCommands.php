<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Cli;

use Forelse\DockerSim\Engine\Docker;
use Forelse\DockerSim\Engine\DockerException;
use Forelse\DockerSim\State\Container;

/** docker network … */
final class NetworkCommands implements CliCommand
{
    private Output $out;

    public function __construct(private readonly Docker $docker, private readonly InspectCommand $inspect)
    {
    }

    public function names(): array
    {
        return ['network'];
    }

    public function run(string $name, array $argv, Output $out): int
    {
        $this->out = $out;

        return match ($name) {
            'network' => $this->network($argv),
            default => throw new \LogicException(sprintf('%s : « %s » est déclarée sans implémentation.', self::class, $name)),
        };
    }

    /** @param list<string> $argv */
    private function network(array $argv): int
    {
        $sub = array_shift($argv);
        switch ($sub) {
            case 'ls':
            case 'list':
                $rows = array_map(static fn ($n) => [substr($n->id, 0, 12), $n->name, $n->driver, 'local'], array_values($this->docker->networks()));
                $this->out->write(Format::table(['NETWORK ID', 'NAME', 'DRIVER', 'SCOPE'], $rows));

                return 0;
            case 'create':
                $args = Args::parse($argv, ['d' => ['driver', true], 'driver' => ['driver', true], 'subnet' => ['subnet', true], 'label' => ['label', true, true], 'internal' => ['internal', false], 'attachable' => ['attachable', false]], 'network create');
                $name = $args->positional[0] ?? throw new UsageError("\"docker network create\" requires exactly 1 argument.\nSee 'docker network create --help'.", 1);
                $this->out->line($this->docker->createNetwork($name)->id);

                return 0;
            case 'rm':
            case 'remove':
                $code = 0;
                foreach ($argv as $name) {
                    try {
                        $this->docker->removeNetwork($name);
                        $this->out->line($name);
                    } catch (DockerException $e) {
                        $this->out->line('Error response from daemon: '.$e->getMessage());
                        $code = 1;
                    }
                }

                return $code;
            case 'inspect':
                return $this->inspect->inspect($argv, 'network', $this->out);
            case 'connect':
                $args = Args::parse($argv, ['alias' => ['alias', true, true], 'ip' => ['ip', true]], 'network connect');
                $this->docker->connect($this->findContainer($args->positional[1] ?? ''), $args->positional[0] ?? '', $args->all('alias'));

                return 0;
            case 'disconnect':
                $this->docker->disconnect($this->findContainer($argv[1] ?? ''), $argv[0] ?? '');

                return 0;
            case 'prune':
                $removed = $this->docker->pruneNetworks();
                $this->out->line("WARNING! This will remove all custom networks not used by at least one container.\n".($removed !== [] ? "Deleted Networks:\n".implode("\n", $removed) : ''));

                return 0;
            default:
                return Application::unknown($this->out, 'network '.($sub ?? ''));
        }
    }

    private function findContainer(string $name): Container
    {
        return $this->docker->findContainer($name) ?? throw new DockerException(sprintf('No such container: %s', $name), 1);
    }
}
