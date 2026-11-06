<?php

namespace App\Api;

use Symfony\Component\Validator\Constraints as Assert;

/** Corps (facultatif) de POST /api/progress/{track}/{exercise}/solution. */
final readonly class SolutionInput
{
    public function __construct(
        /** Exercice à étapes : la solution de cette étape (et de celles d'avant). */
        #[Assert\Range(min: 0, max: 99)]
        public ?int $step = null,
    ) {
    }
}
