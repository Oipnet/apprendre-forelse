<?php

namespace App\Service;

use App\Api\ExerciseAccessGuard;
use App\Api\GuestProgressInput;
use App\Content\ContentRepository;
use App\Content\Exercise;
use App\Entity\ExerciseProgress;
use App\Entity\User;
use App\Repository\ExerciseProgressRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ProgressService
{
    public function __construct(
        private ExerciseProgressRepository $repository,
        private EntityManagerInterface $entityManager,
        private XpCalculator $xpCalculator,
        private ContentRepository $content,
        private ExerciseAccessGuard $guard,
    ) {
    }

    public function find(User $user, Exercise $exercise): ?ExerciseProgress
    {
        return $this->repository->findOne($user, $exercise->trackId, $exercise->id);
    }

    /**
     * @param array<string, string> $files
     */
    public function saveDraft(User $user, Exercise $exercise, array $files, int $hintsUsed): ExerciseProgress
    {
        $progress = $this->findOrCreate($user, $exercise);
        $progress->saveDraft($this->onlyEditable($exercise, $files), min($hintsUsed, \count($exercise->hints)));
        $this->entityManager->flush();

        return $progress;
    }

    /**
     * La réussite est constatée côté navigateur (les tests y tournent) : c'est falsifiable,
     * ce qui est acceptable pour de l'apprentissage. L'XP n'est attribuée qu'une fois.
     *
     * @return array{xpEarned: int, totalXp: int, alreadyCompleted: bool}
     */
    public function complete(User $user, Exercise $exercise, int $hintsUsed): array
    {
        $progress = $this->findOrCreate($user, $exercise);
        $alreadyCompleted = $progress->isCompleted();
        $hints = max($progress->getHintsUsed(), min($hintsUsed, \count($exercise->hints)));
        // La solution consultée ne rapporte rien : c'est ce que promet la page d'accueil.
        $xp = $progress->complete($hints, $progress->isSolutionRevealed() ? 0 : $this->xpCalculator->xpFor($exercise->xp, $hints));
        $user->addXp($xp);
        $this->entityManager->flush();

        return ['xpEarned' => $xp, 'totalXp' => $user->getXp(), 'alreadyCompleted' => $alreadyCompleted];
    }

    /**
     * Livre la solution de référence, et le note : l'exercice ne rapportera plus d'XP, même réussi
     * ensuite avec son propre code. L'apprenant est prévenu avant (voir Playground.ts).
     *
     * @return array<string, string> fichiers de la solution, par chemin
     */
    public function revealSolution(User $user, Exercise $exercise): array
    {
        $progress = $this->findOrCreate($user, $exercise);
        $progress->revealSolution();
        $this->entityManager->flush();

        return $this->content->solutionFiles($exercise);
    }

    /**
     * @param array<string, mixed> $review
     */
    public function saveReview(User $user, Exercise $exercise, array $review): void
    {
        $this->findOrCreate($user, $exercise)->setReview($review);
        $this->entityManager->flush();
    }

    /**
     * Reprend la progression laissée dans le navigateur par les versions où le premier chapitre se jouait sans compte,
     * sans écraser celle du compte.
     *
     * @param list<GuestProgressInput> $items
     */
    public function importGuestProgress(User $user, array $items): int
    {
        $imported = 0;
        foreach ($items as $item) {
            // On ne reprend que ce que le compte peut ouvrir, dans un parcours visible : une progression importée dans
            // un parcours en préparation le ferait apparaître (hasStarted). La Pratique n'a de toute façon rien à importer.
            $exercise = $this->content->findExercise($item->trackId, $item->exerciseId);
            if (!$exercise || !$this->guard->allows($user, $exercise) || $this->find($user, $exercise)) {
                continue;
            }
            $this->saveDraft($user, $exercise, $item->files, $item->hintsUsed);
            if ($item->completed) {
                $this->complete($user, $exercise, $item->hintsUsed);
            }
            ++$imported;
        }

        return $imported;
    }

    private function findOrCreate(User $user, Exercise $exercise): ExerciseProgress
    {
        $progress = $this->find($user, $exercise);
        if (!$progress) {
            $progress = new ExerciseProgress($user, $exercise->trackId, $exercise->id);
            $this->entityManager->persist($progress);
        }

        return $progress;
    }

    /**
     * @param array<string, string> $files
     *
     * @return array<string, string>
     */
    private function onlyEditable(Exercise $exercise, array $files): array
    {
        // Les motifs (migrations/*.php) couvrent les fichiers créés par la console : ils sont gardés aussi.
        return array_filter($files, static fn (string $path) => $exercise->isEditable($path), \ARRAY_FILTER_USE_KEY);
    }
}
