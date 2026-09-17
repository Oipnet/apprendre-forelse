<?php

namespace App\Content;

/**
 * Une version volontairement cassée de l'application, pour noter les tests écrits par l'apprenant.
 *
 * Ses tests doivent passer sur l'application correcte et échouer sur chaque mutant :
 * un mutant qui « survit » révèle un test manquant.
 */
final readonly class Mutant
{
    /**
     * @param string                                                    $label   ce qui casse, vu par l'apprenant (ex. « le prix disparaît de la carte »)
     * @param list<array{file: string, search: string, replace: string}> $changes remplacements appliqués au projet
     */
    public function __construct(
        public string $id,
        public string $label,
        public array $changes,
    ) {
    }
}
