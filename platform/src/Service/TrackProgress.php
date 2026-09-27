<?php

namespace App\Service;

use App\Content\Chapter;
use App\Content\Track;
use App\Entity\ExerciseProgress;

/**
 * Où en est un apprenant dans un parcours : exercices réussis et restants, par chapitre ou en tout, l'exercice où
 * reprendre, l'XP gagnée. Un visiteur a une progression vide.
 */
final readonly class TrackProgress
{
    public const string TODO = 'todo';

    /** @param array<string, ExerciseProgress> $progress par identifiant d'exercice */
    private function __construct(
        private Track $track,
        private array $progress,
    ) {
    }

    /** @param array<string, ExerciseProgress> $progressMap la progression du parcours, par identifiant d'exercice (ExerciseProgressRepository::findByTrack) */
    public static function of(Track $track, array $progressMap): self
    {
        return new self($track, $progressMap);
    }

    /** « todo », « in_progress » ou « completed » (ProgressStatus). */
    public static function stateFrom(?ExerciseProgress $progress): string
    {
        return $progress?->getStatus()->value ?? self::TODO;
    }

    public function stateOf(string $exerciseId): string
    {
        return self::stateFrom($this->progress[$exerciseId] ?? null);
    }

    public function isCompleted(string $exerciseId): bool
    {
        return ($this->progress[$exerciseId] ?? null)?->isCompleted() ?? false;
    }

    public function hasStarted(): bool
    {
        return [] !== $this->progress;
    }

    public function total(): int
    {
        return \count($this->track->exerciseIds());
    }

    /** Exercices du parcours réussis. */
    public function completedCount(): int
    {
        return \count(array_filter($this->track->exerciseIds(), $this->isCompleted(...)));
    }

    /** Exercices du parcours encore à réussir. */
    public function remaining(): int
    {
        return $this->total() - $this->completedCount();
    }

    /** Exercices du chapitre encore à réussir. */
    public function remainingIn(Chapter $chapter): int
    {
        return \count(array_filter($chapter->exerciseIds, fn (string $id) => !$this->isCompleted($id)));
    }

    /** Tous les exercices du parcours réussis (un parcours vide n'est jamais terminé). */
    public function isComplete(): bool
    {
        return $this->total() > 0 && 0 === $this->remaining();
    }

    /** Où reprendre : le premier exercice pas encore réussi, dans l'ordre du parcours. */
    public function next(): ?string
    {
        return array_find($this->track->exerciseIds(), fn (string $id) => !$this->isCompleted($id));
    }

    /** L'XP gagnée sur les exercices du parcours. */
    public function xpEarned(): int
    {
        return array_sum(array_map(fn (string $id) => ($this->progress[$id] ?? null)?->getXpEarned() ?? 0, $this->track->exerciseIds()));
    }

    public function xpEarnedOn(string $exerciseId): ?int
    {
        return ($this->progress[$exerciseId] ?? null)?->getXpEarned();
    }

    /**
     * La fiche de cours du chapitre : une récompense de fin de chapitre, lisible une fois ses exercices réussis. Un
     * relecteur (auteur, administrateur) la lit toujours.
     *
     * @return array{unlocked: bool, remaining: int} remaining : exercices du chapitre encore à réussir
     */
    public function lesson(Chapter $chapter, bool $reviewer): array
    {
        if ($reviewer) {
            return ['unlocked' => true, 'remaining' => 0];
        }
        $remaining = $this->remainingIn($chapter);

        return ['unlocked' => 0 === $remaining, 'remaining' => $remaining];
    }
}
