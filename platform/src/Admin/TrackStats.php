<?php

namespace App\Admin;

use App\Entity\ExerciseProgress;

final readonly class TrackStats
{
    /**
     * @param list<ChapterStats>                                        $chapters
     * @param array<int, array<string, array<string, ExerciseProgress>>> $cells    progression par apprenant, parcours et exercice
     * @param array<string, string>                                       $titles   titre de chaque exercice du parcours
     */
    public function __construct(
        public string $id,
        public string $title,
        public array $chapters,
        private array $cells,
        public array $titles,
    ) {
    }

    public function completed(): int
    {
        return array_sum(array_map(static fn (ChapterStats $c) => $c->completed, $this->chapters));
    }

    public function progressOf(int $userId, string $exerciseId): ?ExerciseProgress
    {
        return $this->cells[$userId][$this->id][$exerciseId] ?? null;
    }

    /** Nombre d'exercices du parcours réussis par un apprenant. */
    public function completedBy(int $userId): int
    {
        return \count(array_filter($this->cells[$userId][$this->id] ?? [], static fn (ExerciseProgress $p) => $p->isCompleted()));
    }

    public function exerciseCount(): int
    {
        return \count($this->titles);
    }
}
