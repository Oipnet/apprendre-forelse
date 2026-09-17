<?php

namespace App\Api;

use Symfony\Component\Validator\Constraints as Assert;

/** Progression d'un exercice joué en invité, remontée après inscription ou connexion. */
final readonly class GuestProgressInput
{
    /**
     * @param array<string, string> $files
     */
    public function __construct(
        #[Assert\NotBlank] public string $trackId,
        #[Assert\NotBlank] public string $exerciseId,
        #[Assert\Count(max: 20)]
        #[Assert\All([new Assert\Type('string'), new Assert\Length(max: 100_000)])]
        public array $files = [],
        #[Assert\Range(min: 0, max: 20)]
        public int $hintsUsed = 0,
        public bool $completed = false,
    ) {
    }
}
