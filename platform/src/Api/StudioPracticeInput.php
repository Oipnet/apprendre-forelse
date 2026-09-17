<?php

namespace App\Api;

use Symfony\Component\Validator\Constraints as Assert;

/** Corps de POST /atelier/pratique/nouveau. */
final readonly class StudioPracticeInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'Choisissez le pack qui accueille l\'exercice.')]
        public string $pack = '',
        #[Assert\NotBlank(message: 'Donnez un identifiant à l\'exercice.')]
        #[Assert\Regex('/^[a-z0-9][a-z0-9-]*$/', message: 'Identifiant : minuscules, chiffres et tirets (ex. symfony-8-1-map-query-string).')]
        public string $id = '',
        #[Assert\NotBlank(message: 'Donnez un titre à l\'exercice.')]
        #[Assert\Length(max: 120)]
        public string $titre = '',
        #[Assert\NotBlank(message: 'Choisissez l\'environnement de l\'exercice.')]
        public string $environnement = '',
    ) {
    }
}
