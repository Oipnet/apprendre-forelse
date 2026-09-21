<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Shell;

final class Result
{
    public function __construct(
        public readonly int $code = 0,
        public readonly string $stdout = '',
        public readonly string $stderr = '',
        /** Durée simulée (ce que la vraie commande aurait pris), pour l'affichage du build. */
        public readonly float $seconds = 0.0,
    ) {
    }

    public static function ok(string $stdout = '', float $seconds = 0.0): self
    {
        return new self(0, $stdout, '', $seconds);
    }

    public static function error(int $code, string $stderr, string $stdout = '', float $seconds = 0.0): self
    {
        return new self($code, $stdout, $stderr, $seconds);
    }
}
