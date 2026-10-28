<?php

namespace App\Seo;

/** Le résultat d'un passage d'IndexNow : les adresses envoyées, et la raison d'un échec. */
final readonly class IndexNowSubmission
{
    public function __construct(
        public int $submitted,
        public ?string $error = null,
    ) {
    }
}
