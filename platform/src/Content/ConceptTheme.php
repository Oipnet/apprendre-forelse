<?php

namespace App\Content;

/**
 * Un thème de l'index des notions (« Doctrine & base de données »), déclaré par un pack dans
 * `<pack>/notions/themes.yaml` : il range les notions d'un framework sous un titre et une phrase.
 */
final readonly class ConceptTheme
{
    /**
     * @param list<string> $concepts les notions du thème, par leur libellé, dans l'ordre du fichier
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $description,
        public array $concepts,
    ) {
    }
}
