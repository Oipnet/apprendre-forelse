<?php

namespace App\Content\Author;

use App\Content\ContentException;
use App\Content\ContentRepository;
use App\Content\Exercise;
use App\Content\Pack;
use App\Content\Track;

/**
 * Ce que le modèle apporte à l'atelier : un nouvel exercice écrit à partir d'un sujet, la correction d'un exercice que
 * content:check refuse. Le modèle propose ; l'atelier (ExerciseStudio) écrit et vérifie.
 */
final readonly class ExerciseAssistant
{
    public function __construct(
        private ContentRepository $content,
        private ExerciseStudio $studio,
        private ExerciseDrafter $drafter,
    ) {
    }

    /**
     * Crée l'exercice ; avec un sujet, ses fichiers sont un brouillon du modèle plutôt que le squelette.
     *
     * @param string|null $base exercice dont l'état final sert de point de départ
     *
     * @return string l'identifiant de l'exercice créé
     *
     * @throws ContentException
     */
    public function creer(Track $track, string $chapitreId, string $id, string $titre, ?string $base, string $sujet): string
    {
        $base = '' === (string) $base ? null : $base;
        $brouillon = '' === trim($sujet)
            ? null
            : $this->drafter->brouillon($track, $id, $titre, $sujet, null === $base ? null : $this->content->findExercise($track->id, $base));

        return $this->studio->creer($track, $chapitreId, $id, $titre, $base, $brouillon);
    }

    /**
     * Des fichiers corrigés d'après le rapport de content:check : proposés, pas enregistrés.
     *
     * @return array{fichiers: array<string, string>, erreurs: list<string>}
     *
     * @throws ContentException exercice déjà conforme, ou réponse du modèle inutilisable
     */
    public function proposerCorrection(Track|Pack $owner, Exercise $exercise): array
    {
        $verdict = $this->studio->verifier($exercise);
        if ($verdict->isOk()) {
            throw new ContentException('L\'exercice est déjà conforme : rien à corriger.');
        }

        return [
            'fichiers' => $this->drafter->corriger($owner instanceof Track ? $owner : null, $exercise, $this->studio->lire($exercise), $verdict->errors),
            'erreurs' => $verdict->errors,
        ];
    }
}
