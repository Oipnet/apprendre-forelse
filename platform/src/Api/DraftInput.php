<?php

namespace App\Api;

use Symfony\Component\Validator\Constraints as Assert;

/** Corps de PUT /api/progress/{track}/{exercise}. */
final readonly class DraftInput
{
    /**
     * @param array<string, string> $files brouillons des fichiers éditables
     */
    public function __construct(
        #[Assert\Count(max: 20)]
        #[Assert\All([new Assert\Type('string'), new Assert\Length(max: 100_000)])]
        public array $files = [],
        #[Assert\Range(min: 0, max: 20)]
        public int $hintsUsed = 0,
    ) {
    }
}
