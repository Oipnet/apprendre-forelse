<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Cli;

final class UsageError extends \RuntimeException
{
    public function __construct(string $message, public readonly int $exitCode = 125)
    {
        parent::__construct($message);
    }
}
