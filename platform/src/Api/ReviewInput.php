<?php

namespace App\Api;

use Symfony\Component\Validator\Constraints as Assert;

/** Corps de POST /api/mentor/{track}/{exercise}/review : le code qui vient de réussir. */
final readonly class ReviewInput
{
    /**
     * @param array<string, string> $files
     */
    public function __construct(
        #[Assert\Count(max: 50)]
        #[Assert\All([new Assert\Type('string')])]
        public array $files = [],
    ) {
    }
}
