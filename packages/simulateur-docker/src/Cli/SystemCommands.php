<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Cli;

use Forelse\DockerSim\Engine\Docker;
use Forelse\DockerSim\State\Container;
use Forelse\DockerSim\State\Image;

/** docker version, info, system df / prune / info. */
final class SystemCommands implements CliCommand
{
    private Output $out;

    public function __construct(private readonly Docker $docker)
    {
    }

    public function names(): array
    {
        return ['version', 'info', 'system'];
    }

    public function run(string $name, array $argv, Output $out): int
    {
        $this->out = $out;

        return match ($name) {
            'version' => $this->version(),
            'info' => $this->info(),
            'system' => $this->system($argv),
            default => throw new \LogicException(sprintf('%s : « %s » est déclarée sans implémentation.', self::class, $name)),
        };
    }

    private function version(): int
    {
        $this->out->line("Client:\n Version:           ".Application::VERSION."\n API version:       1.51\n Go version:        go1.24.6\n OS/Arch:           linux/amd64\n Context:           default\n\nServer: Docker Engine - Community (simulateur forelse)\n Engine:\n  Version:          ".Application::VERSION."\n  API version:      1.51 (minimum version 1.24)\n  OS/Arch:          linux/amd64\n containerd:\n  Version:          1.7.27\n runc:\n  Version:          1.2.5");

        return 0;
    }

    private function info(): int
    {
        $containers = $this->docker->containers();
        $running = \count(array_filter($containers, static fn (Container $c) => $c->isRunning()));
        $this->out->line(sprintf("Client:\n Version:    %s\n Context:    default\n\nServer:\n Containers: %d\n  Running: %d\n  Paused: 0\n  Stopped: %d\n Images: %d\n Server Version: %s\n Storage Driver: overlayfs (simulé)\n Cgroup Driver: cgroupfs\n Kernel Version: 6.10.14-linuxkit\n Operating System: Simulateur Docker (forelse), dans votre navigateur\n OSType: linux\n Architecture: x86_64\n CPUs: 4\n Total Memory: 7.66GiB\n Docker Root Dir: /var/lib/docker", Application::VERSION, \count($containers), $running, \count($containers) - $running, \count($this->docker->images()), Application::VERSION));

        return 0;
    }

    /** @param list<string> $argv */
    private function system(array $argv): int
    {
        $sub = array_shift($argv);
        if ($sub === 'df') {
            $images = $this->docker->images();
            $containers = $this->docker->containers();
            $volumes = $this->docker->volumes();
            $cache = $this->docker->buildCacheSize();
            $imagesSize = array_sum(array_map(static fn (Image $i) => $i->size(), $images));
            $active = \count(array_unique(array_map(static fn (Container $c) => $c->imageId, $containers)));
            $this->out->write(Format::table(['TYPE', 'TOTAL', 'ACTIVE', 'SIZE', 'RECLAIMABLE'], [
                ['Images', (string) \count($images), (string) $active, Format::size($imagesSize), Format::size((int) ($imagesSize * 0.3))],
                ['Containers', (string) \count($containers), (string) \count(array_filter($containers, static fn (Container $c) => $c->isRunning())), '0B', '0B'],
                ['Local Volumes', (string) \count($volumes), (string) \count(array_filter(array_keys($volumes), fn ($n) => $this->docker->volumeInUse((string) $n))), '0B', '0B'],
                ['Build Cache', (string) $cache, '0', Format::size($cache * 12_000_000), Format::size($cache * 12_000_000)],
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
            $this->out->line(implode("\n", $lines)."\n");
            $this->docker->pruneContainers();
            $this->docker->pruneNetworks();
            $reclaimed = array_sum(array_map(static fn (Image $i) => $i->size(), $this->docker->pruneImages($args->has('all'))));
            if ($args->has('volumes')) {
                $this->docker->pruneVolumes(false);
            }
            $cache = $this->docker->pruneBuildCache();
            $this->out->line('Total reclaimed space: '.Format::size($reclaimed + $cache * 12_000_000));

            return 0;
        }
        if ($sub === 'info') {
            return $this->info();
        }

        return Application::unknown($this->out, 'system '.($sub ?? ''));
    }
}
