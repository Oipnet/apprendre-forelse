<?php

namespace App\Api;

use Symfony\Component\Validator\Constraints as Assert;

/** Corps de POST /api/progress/{track}/{exercise}/complete. */
final readonly class CompletionInput
{
    public function __construct(
        #[Assert\Range(min: 0, max: 20)]
        public int $hintsUsed = 0,
    ) {
    }
}
