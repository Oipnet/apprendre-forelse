<?php

namespace App\Api;

use Symfony\Component\Validator\Constraints as Assert;

/** Corps de PUT /atelier/{track}/{exercice} : tous les fichiers de l'exercice. */
final readonly class StudioSaveInput
{
    /**
     * @param array<string, string> $fichiers contenu par chemin relatif (exercise.yaml, starter/…)
     */
    public function __construct(
        #[Assert\Count(min: 1, max: 200, minMessage: 'Un exercice ne peut pas être vide.')]
        #[Assert\All([new Assert\Type('string'), new Assert\Length(max: 200_000)])]
        public array $fichiers = [],
    ) {
    }
}
