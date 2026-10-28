<?php

namespace App\Content;

/**
 * Ce qui contient un exercice, pour l'atelier : son parcours, ou son pack pour un exercice de Pratique. Ce que
 * l'atelier fait de l'un fait l'autre (dossier modifiable, fil d'Ariane), sans demander lequel c'est ; seul ce qui
 * diffère vraiment (track.yaml d'un parcours) garde un test de type.
 */
interface ExerciseOwner
{
    public string $id { get; }

    public string $title { get; }

    /** Le dossier où vivent ses exercices. */
    public string $directory { get; }

    /** Un pack, pour ses exercices de Pratique ; sinon un parcours. */
    public function holdsPractice(): bool;
}
