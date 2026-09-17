<?php

namespace App\Api;

use Symfony\Component\Validator\Constraints as Assert;

/** Corps des requêtes de l'atelier sur une fiche de cours : le markdown de lesson.md (vide = pas de fiche). */
final readonly class StudioLessonInput
{
    public function __construct(
        #[Assert\Length(max: 200_000)]
        public string $markdown = '',
    ) {
    }
}
