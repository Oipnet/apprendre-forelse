<?php

namespace App\Content;

/** Lien vers la documentation qui aide à résoudre un exercice (clé « docs » d'exercise.yaml). */
final readonly class DocLink
{
    public function __construct(
        public string $title,
        public string $url,
    ) {
    }
}
