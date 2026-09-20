<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Build;

use Forelse\DockerSim\State\Image;

/** Ce qu'a produit un docker build : la sortie (format BuildKit), l'image, les étapes, les avertissements. */
final class BuildResult
{
    /**
     * @param list<array{name: string, instruction: string, cached: bool, seconds: float, line: int, output: string}> $steps
     * @param list<string> $warnings
     * @param list<string> $notes     remarques du simulateur (ce qu'il n'exécute pas vraiment)
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $output,
        public readonly ?Image $image,
        public readonly array $steps,
        public readonly array $warnings,
        public readonly ?string $error = null,
        public readonly array $notes = [],
        public readonly int $contextSize = 0,
    ) {
    }

    public function cachedSteps(): int
    {
        return \count(array_filter($this->steps, static fn ($s) => $s['cached']));
    }

    /** Durée simulée totale (ce que le vrai build aurait pris). */
    public function seconds(): float
    {
        return array_sum(array_map(static fn ($s) => $s['cached'] ? 0.0 : $s['seconds'], $this->steps));
    }

    public function hasWarning(string $rule): bool
    {
        foreach ($this->warnings as $warning) {
            if (str_starts_with($warning, $rule.':')) {
                return true;
            }
        }

        return false;
    }
}
