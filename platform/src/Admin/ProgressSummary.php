<?php

namespace App\Admin;

use App\Entity\ProgressStatus;

/**
 * Ce que les tableaux de bord montrent d'une progression : son état, pas son contenu. Lu par une requête partielle
 * (ExerciseProgressRepository::summariesByUsers), sans les brouillons de code (colonne files) ni la revue du mentor.
 */
final readonly class ProgressSummary
{
    public function __construct(
        public int $userId,
        public string $trackId,
        public string $exerciseId,
        public ProgressStatus $status,
        public int $hintsUsed,
        public \DateTimeImmutable $startedAt,
        public ?\DateTimeImmutable $completedAt,
    ) {
    }

    public function isCompleted(): bool
    {
        return ProgressStatus::Completed === $this->status;
    }
}
