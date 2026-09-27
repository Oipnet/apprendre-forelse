<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Shell\Command;

use Forelse\DockerSim\Shell\Interpreter;
use Forelse\DockerSim\Shell\Machine;
use Forelse\DockerSim\Shell\Result;

/** Les archives et la compression : tar, gzip, xz, zip… Non simulées, elles réussissent en le signalant. */
final class ArchiveCommands extends CoreutilsCommands
{
    public function names(): array
    {
        return ['tar', 'gzip', 'gunzip', 'xz', 'bzip2', 'unzip', 'zip'];
    }

    /** @param list<string> $args */
    protected function dispatch(string $name, array $args, Machine $m, string $stdin, Interpreter $sh): Result
    {
        return match ($name) {
            'tar', 'gzip', 'gunzip', 'xz', 'bzip2', 'unzip', 'zip' => $this->archive($name, $args, $m),
            default => throw new \LogicException(sprintf('%s : « %s » est déclarée sans implémentation.', self::class, $name)),
        };
    }

    /** @param list<string> $args */
    private function archive(string $name, array $args, Machine $m): Result
    {
        $m->note(sprintf('« %s » : les archives ne sont pas simulées (commande considérée comme réussie).', $name));

        return Result::ok('', 0.3);
    }
}
