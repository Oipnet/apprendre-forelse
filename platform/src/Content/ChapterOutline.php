<?php

namespace App\Content;

/**
 * Le sommaire public d'un chapitre : ce qu'il fait apprendre et par quels exercices, lisible sans compte.
 *
 * C'est le niveau qui manquait entre le parcours et ses exercices — un parcours de 74 exercices n'avait qu'une
 * page pour les annoncer tous. Rien de ce qui résout un exercice n'y figure : ni indices, ni tests, ni solution,
 * ni le texte des objectifs (que des tests vérifient). La fiche de cours, elle, reste réservée (ChapterController).
 */
final readonly class ChapterOutline
{
    public function __construct(
        private ContentRepository $content,
        private ExerciseStory $stories,
    ) {
    }

    /**
     * @return array{
     *     chapter: Chapter,
     *     number: int,
     *     exercises: list<array{exercise: Exercise, position: int, story: string}>,
     *     concepts: list<string>,
     *     xp: int,
     *     duration: int|null,
     *     previous: array{chapter: Chapter, number: int}|null,
     *     next: array{chapter: Chapter, number: int}|null,
     * }|null null si le chapitre n'a aucun exercice lisible
     */
    public function of(Track $track, Chapter $chapter): ?array
    {
        $exercises = [];
        foreach ($chapter->exerciseIds as $position => $exerciseId) {
            $exercise = $this->content->findExercise($track->id, $exerciseId);
            if (null !== $exercise) {
                $exercises[] = [
                    'exercise' => $exercise,
                    'position' => $position + 1,
                    'story' => $this->stories->fromInstructions($exercise->instructions),
                ];
            }
        }
        if ([] === $exercises) {
            return null;
        }

        $number = self::numberOf($track, $chapter);

        return [
            'chapter' => $chapter,
            'number' => $number,
            'exercises' => $exercises,
            'concepts' => array_values(array_unique(array_merge([], ...array_map(static fn (array $row) => $row['exercise']->concepts, $exercises)))),
            'xp' => array_sum(array_map(static fn (array $row) => $row['exercise']->xp, $exercises)),
            'duration' => $this->content->durationOf($track, $chapter),
            'previous' => self::neighbour($track, $number - 2),
            'next' => self::neighbour($track, $number),
        ];
    }

    /** Le rang du chapitre dans le parcours, à partir de 1. */
    public static function numberOf(Track $track, Chapter $chapter): int
    {
        return (int) array_search($chapter->id, array_map(static fn (Chapter $c) => $c->id, $track->chapters), true) + 1;
    }

    /** @return array{chapter: Chapter, number: int}|null le chapitre à cet index, s'il existe */
    private static function neighbour(Track $track, int $index): ?array
    {
        $chapter = $index >= 0 ? ($track->chapters[$index] ?? null) : null;

        return null === $chapter ? null : ['chapter' => $chapter, 'number' => $index + 1];
    }
}
