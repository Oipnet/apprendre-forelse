<?php

namespace App\Api;

use Symfony\Component\Validator\Constraints as Assert;

/** Corps de POST /atelier/{track}/post : une consigne libre de l'auteur, ou rien. */
final readonly class StudioPostInput
{
    public function __construct(
        /** Ce que l'auteur veut voir dans le post (angle, public visé, actualité à raccrocher). Vide : le modèle décide. */
        #[Assert\Length(max: 500)]
        public string $precision = '',
    ) {
    }
}
