<?php

namespace App\Api;

use Symfony\Component\Validator\Constraints as Assert;

/** Corps de POST /atelier/{track}/nouveau. */
final readonly class StudioCreateInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'Donnez un identifiant à l\'exercice.')]
        #[Assert\Regex('/^[a-z0-9][a-z0-9-]*$/', message: 'Identifiant : minuscules, chiffres et tirets (ex. 45-le-grand-menage).')]
        public string $id = '',
        #[Assert\NotBlank(message: 'Donnez un titre à l\'exercice.')]
        #[Assert\Length(max: 120)]
        public string $titre = '',
        #[Assert\NotBlank(message: 'Choisissez le chapitre qui accueille l\'exercice.')]
        public string $chapitre = '',
        /** Exercice dont l'état final sert de point de départ (facultatif). */
        public ?string $base = null,
        /** Sujet confié au modèle : s'il est rempli, l'exercice est rédigé par l'IA. */
        #[Assert\Length(max: 2000)]
        public string $sujet = '',
    ) {
    }
}
