<?php

namespace App\Defense;

use App\Content\ContentRepository;
use App\Content\Track;
use App\Entity\Defense;
use App\Entity\ExerciseProgress;
use App\Repository\ExerciseProgressRepository;
use App\Service\TrackProgress;

/**
 * Le dossier de soutenance, pour l'évaluateur : chaque exercice du parcours avec le code rendu par l'apprenant, les
 * indices utilisés, la solution consultée ou non, et la revue du mentor. Lu tel qu'il est, au moment où on l'ouvre.
 */
final readonly class DefenseDossier
{
    public function __construct(
        private ContentRepository $content,
        private ExerciseProgressRepository $progress,
    ) {
    }

    /**
     * @return array{track: Track, complete: bool, completed: int, total: int, solutions: int, chapters: list<array{chapter: \App\Content\Chapter, exercises: list<array{id: string, title: string, hints: int, progress: ?ExerciseProgress}>}>}
     */
    public function of(Defense $defense): array
    {
        $track = $this->content->findTrack($defense->getTrackId()) ?? throw new DefenseRuleViolation('Ce parcours n\'existe plus.');
        $learner = $defense->getLearner();
        $progress = null === $learner ? [] : $this->progress->findByTrack($learner, $track->id);
        $summary = TrackProgress::of($track, $progress);

        $chapters = [];
        foreach ($track->chapters as $chapter) {
            $exercises = [];
            foreach ($chapter->exerciseIds as $exerciseId) {
                $exercise = $this->content->findExercise($track->id, $exerciseId);
                $exercises[] = [
                    'id' => $exerciseId,
                    'title' => $exercise->title ?? $exerciseId,
                    'hints' => \count($exercise->hints ?? []),
                    'progress' => $progress[$exerciseId] ?? null,
                ];
            }
            $chapters[] = ['chapter' => $chapter, 'exercises' => $exercises];
        }

        return [
            'track' => $track,
            'complete' => $summary->isComplete(),
            'completed' => $summary->completedCount(),
            'total' => $summary->total(),
            'solutions' => \count(array_filter($progress, static fn (ExerciseProgress $p) => $p->isSolutionRevealed() && \in_array($p->getExerciseId(), $track->exerciseIds(), true))),
            'chapters' => $chapters,
        ];
    }
}
