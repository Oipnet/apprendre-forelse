<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Dockerfile;

/** Erreur de syntaxe, formulée comme BuildKit : « dockerfile parse error on line N: … ». */
final class ParseError extends \RuntimeException
{
    public function __construct(public readonly int $dockerfileLine, string $detail)
    {
        parent::__construct(sprintf('dockerfile parse error on line %d: %s', $dockerfileLine, $detail));
    }
}
