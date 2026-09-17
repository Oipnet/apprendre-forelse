<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Engine;

/** Erreur renvoyée par le « démon » (« Error response from daemon: … »), avec le code de sortie du client. */
final class DockerException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $exitCode = 125, public readonly bool $daemon = true)
    {
        parent::__construct($message);
    }
}
