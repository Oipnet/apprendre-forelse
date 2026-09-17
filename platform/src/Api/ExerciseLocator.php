<?php

namespace App\Api;

use App\Content\ContentRepository;
use App\Content\Exercise;
use App\Content\PracticeVisibility;

/**
 * Retrouve l'exercice désigné par une route de l'API : celui d'un parcours, ou, sans parcours (routes
 * « …_pratique »), un exercice de Pratique visible de l'utilisateur courant.
 */
final readonly class ExerciseLocator
{
    public function __construct(
        private ContentRepository $content,
        private PracticeVisibility $practices,
    ) {
    }

    public function find(?string $trackId, string $exerciseId): ?Exercise
    {
        // Recherche dans le contenu déjà chargé : aucun chemin de fichier n'est construit depuis l'URL.
        return null === $trackId
            ? $this->practices->find($exerciseId)?->exercise
            : $this->content->findExercise($trackId, $exerciseId);
    }
}
