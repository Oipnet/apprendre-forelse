<?php

namespace App\Service;

use App\Content\Chapter;
use App\Content\Track;
use App\Entity\User;
use App\Repository\ExerciseProgressRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Qui peut lire la fiche de cours d'un chapitre : l'apprenant qui a réussi tous ses exercices.
 * C'est une récompense de fin de chapitre, pas un raccourci pour éviter les exercices.
 * Les auteurs (et donc les administrateurs) la voient toujours, pour la relire.
 */
final readonly class LessonAccess
{
    public function __construct(
        private ExerciseProgressRepository $progressRepository,
        private Security $security,
    ) {
    }

    /**
     * @param array<string, \App\Entity\ExerciseProgress>|null $progress progression déjà chargée pour ce parcours (évite une requête)
     *
     * @return array{unlocked: bool, remaining: int} remaining : exercices du chapitre encore à réussir
     */
    public function status(?User $user, Track $track, Chapter $chapter, ?array $progress = null): array
    {
        if ($this->security->isGranted(User::ROLE_AUTEUR)) {
            return ['unlocked' => true, 'remaining' => 0];
        }
        if (!$user) {
            return ['unlocked' => false, 'remaining' => \count($chapter->exerciseIds)];
        }
        $progress ??= $this->progressRepository->findByTrack($user, $track->id);
        $remaining = \count(array_filter($chapter->exerciseIds, static fn (string $id) => !isset($progress[$id]) || !$progress[$id]->isCompleted()));

        return ['unlocked' => 0 === $remaining, 'remaining' => $remaining];
    }
}
