<?php

namespace App\Service;

use App\Content\Chapter;
use App\Content\ContentRepository;
use App\Content\Exercise;
use App\Content\Track;
use App\Entity\ExerciseProgress;

/** Ce qu'une fiche de cours affiche en tête : numéro du chapitre, concepts abordés, XP gagnés. */
final readonly class ChapterSummary
{
    public function __construct(private ContentRepository $content)
    {
    }

    /**
     * @param array<string, ExerciseProgress> $progress progression de l'apprenant sur le parcours, par exercice
     *
     * @return array{chapter: Chapter, number: int, concepts: list<string>, xp: int}
     */
    public function summarize(Track $track, Chapter $chapter, array $progress): array
    {
        /** @var list<Exercise> $exercises */
        $exercises = array_values(array_filter(array_map(fn (string $id) => $this->content->findExercise($track->id, $id), $chapter->exerciseIds)));
        $position = (int) array_search($chapter->id, array_map(static fn (Chapter $c) => $c->id, $track->chapters), true);

        return [
            'chapter' => $chapter,
            'number' => $position + 1,
            'concepts' => array_values(array_unique(array_merge([], ...array_map(static fn (Exercise $e) => $e->concepts, $exercises)))),
            'xp' => array_sum(array_map(static fn (Exercise $e) => isset($progress[$e->id]) ? $progress[$e->id]->getXpEarned() : 0, $exercises)),
        ];
    }
}
