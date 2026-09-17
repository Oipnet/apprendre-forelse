<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Dockerfile;

/** Un Dockerfile analysé : ses ARG globaux (avant le premier FROM) et ses étapes. */
final class Dockerfile
{
    /**
     * @param list<Instruction> $globalArgs ARG placés avant le premier FROM
     * @param list<Stage>       $stages
     */
    public function __construct(
        public readonly array $globalArgs,
        public readonly array $stages,
        public readonly string $escape = '\\',
    ) {
    }

    public function stage(string $labelOrIndex): ?Stage
    {
        foreach ($this->stages as $stage) {
            if ($stage->name !== null && strcasecmp($stage->name, $labelOrIndex) === 0) {
                return $stage;
            }
        }
        if (ctype_digit($labelOrIndex) && isset($this->stages[(int) $labelOrIndex])) {
            return $this->stages[(int) $labelOrIndex];
        }

        return null;
    }

    public function lastStage(): Stage
    {
        return $this->stages[\count($this->stages) - 1];
    }
}
