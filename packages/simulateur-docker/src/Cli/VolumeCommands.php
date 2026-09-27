<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Cli;

use Forelse\DockerSim\Engine\Docker;
use Forelse\DockerSim\Engine\DockerException;

/** docker volume … */
final class VolumeCommands implements CliCommand
{
    private Output $out;

    public function __construct(private readonly Docker $docker, private readonly InspectCommand $inspect)
    {
    }

    public function names(): array
    {
        return ['volume'];
    }

    public function run(string $name, array $argv, Output $out): int
    {
        $this->out = $out;

        return match ($name) {
            'volume' => $this->volume($argv),
            default => throw new \LogicException(sprintf('%s : « %s » est déclarée sans implémentation.', self::class, $name)),
        };
    }

    /** @param list<string> $argv */
    private function volume(array $argv): int
    {
        $sub = array_shift($argv);
        switch ($sub) {
            case 'ls':
            case 'list':
                $args = Args::parse($argv, ['q' => ['quiet', false], 'quiet' => ['quiet', false], 'f' => ['filter', true, true], 'filter' => ['filter', true, true]], 'volume ls');
                $volumes = array_values($this->docker->volumes());
                if (\in_array('dangling=true', $args->all('filter'), true)) {
                    $volumes = array_values(array_filter($volumes, fn ($v) => !$this->docker->volumeInUse($v->name)));
                }
                if ($args->has('quiet')) {
                    $this->out->write(implode('', array_map(static fn ($v) => $v->name."\n", $volumes)));

                    return 0;
                }
                $this->out->write(Format::table(['DRIVER', 'VOLUME NAME'], array_map(static fn ($v) => ['local', $v->name], $volumes)));

                return 0;
            case 'create':
                $name = $argv[0] ?? hash('sha256', random_bytes(16));
                $this->docker->createVolume($name);
                $this->out->line($name);

                return 0;
            case 'rm':
            case 'remove':
                $args = Args::parse($argv, ['f' => ['force', false], 'force' => ['force', false]], 'volume rm');
                $code = 0;
                foreach ($args->positional as $name) {
                    try {
                        $existait = isset($this->docker->volumes()[$name]);
                        $this->docker->removeVolume($name, $args->has('force'));
                        if ($existait) {
                            $this->out->line($name);
                        }
                    } catch (DockerException $e) {
                        $this->out->line('Error response from daemon: '.$e->getMessage());
                        $code = 1;
                    }
                }

                return $code;
            case 'inspect':
                return $this->inspect->inspect($argv, 'volume', $this->out);
            case 'prune':
                $args = Args::parse($argv, ['a' => ['all', false], 'all' => ['all', false], 'f' => ['force', false], 'force' => ['force', false], 'filter' => ['filter', true, true]], 'volume prune');
                $removed = $this->docker->pruneVolumes($args->has('all'));
                if (!$args->has('force')) {
                    $this->out->line('WARNING! This will remove anonymous local volumes not used by at least one container.');
                }
                $this->out->line(($removed !== [] ? "Deleted Volumes:\n".implode("\n", $removed)."\n\n" : '').'Total reclaimed space: 0B');

                return 0;
            default:
                return Application::unknown($this->out, 'volume '.($sub ?? ''));
        }
    }
}
