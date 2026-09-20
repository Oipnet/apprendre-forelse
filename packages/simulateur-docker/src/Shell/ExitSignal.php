<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Shell;

/** « exit N », « set -e » après un échec, ou « exec » : interrompt le script en cours. */
final class ExitSignal extends \RuntimeException
{
    public function __construct(public readonly int $exitCode, public readonly string $output)
    {
        parent::__construct('exit '.$exitCode);
    }

    public function __get(string $name): mixed
    {
        return $name === 'code' ? $this->exitCode : null;
    }
}
