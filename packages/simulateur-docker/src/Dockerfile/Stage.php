<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Dockerfile;

/** Une étape de build (un FROM et ce qui le suit), nommée ou non. */
final class Stage
{
    /** @param list<Instruction> $instructions le FROM inclus, en première position */
    public function __construct(
        public readonly int $index,
        public readonly ?string $name,
        public readonly Instruction $from,
        public array $instructions,
    ) {
    }

    /** Ce par quoi on désigne l'étape dans --from= ou --target= : son nom, sinon son numéro. */
    public function label(): string
    {
        return $this->name ?? (string) $this->index;
    }
}
