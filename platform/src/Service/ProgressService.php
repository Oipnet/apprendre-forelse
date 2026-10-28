<?php

namespace App\Service;

use App\Content\ContentRepository;
use App\Content\Exercise;
use App\Entity\ExerciseProgress;
use App\Entity\User;
use App\Leaderboard\TrackBonuses;
use App\Repository\ExerciseProgressRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final readonly class ProgressService
{
    public function __construct(
        private ExerciseProgressRepository $repository,
        private EntityManagerInterface $entityManager,
        private XpCalculator $xpCalculator,
        private ContentRepository $content,
        private TrackBonuses $trackBonuses,
        private ClockInterface $clock,
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
        $progress->saveDraft($this->onlyEditable($exercise, $files), min($hintsUsed, \count($exercise->hints)), $this->clock->now());
        $this->entityManager->flush();

        return $progress;
    }

    /**
     * La réussite est constatée côté navigateur (les tests y tournent) : c'est falsifiable. L'XP est attribuée
     * tout de suite, une seule fois ; le code est figé, et le service « verificateur » le rejoue plus tard
     * (voir App\Verification) : une réussite infirmée sort du classement.
     *
     * Atomique : deux réussites simultanées (double clic, nouvel essai, deux onglets sur deux exercices) se suivent
     * derrière un verrou sur le compte, et chacune relit l'XP et la progression une fois le verrou obtenu. Sans lui,
     * la seconde comptait l'XP une deuxième fois, ou écrasait le total écrit par la première.
     *
     * @return array{xpEarned: int, trackBonus: int, totalXp: int, alreadyCompleted: bool}
     */
    public function complete(User $user, Exercise $exercise, int $hintsUsed): array
    {
        return $this->entityManager->wrapInTransaction(function () use ($user, $exercise, $hintsUsed): array {
            $this->entityManager->refresh($user, LockMode::PESSIMISTIC_WRITE);
            $progress = $this->findOrCreate($user, $exercise);
            // Déjà chargée plus tôt dans la requête : l'état lu alors peut dater d'avant le verrou.
            $this->entityManager->refresh($progress);
            $alreadyCompleted = $progress->isCompleted();
            $hints = max($progress->getHintsUsed(), min($hintsUsed, \count($exercise->hints)));
            // La solution consultée ne rapporte rien : c'est ce que promet la page d'accueil.
            $xp = $progress->complete($hints, $progress->isSolutionRevealed() ? 0 : $this->xpCalculator->xpFor($exercise->xp, $hints), $this->clock->now(), Exercise::LOCALE === $exercise->locale ? null : $exercise->locale);
            $user->addXp($xp);
            // Le dernier exercice d'un parcours : le bonus du parcours terminé (une seule fois).
            $track = null === $exercise->trackId ? null : $this->content->findTrack($exercise->trackId);
            $trackBonus = null === $track ? 0 : $this->trackBonuses->awardIfComplete($user, $track);

            return ['xpEarned' => $xp, 'trackBonus' => $trackBonus, 'totalXp' => $user->getXp(), 'alreadyCompleted' => $alreadyCompleted];
        });
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
        $progress->revealSolution($this->clock->now());
        $this->entityManager->flush();

        return $this->content->solutionFiles($exercise);
    }

    /**
     * @param array<string, mixed> $review
     */
    public function saveReview(User $user, Exercise $exercise, array $review): void
    {
        $this->findOrCreate($user, $exercise)->setReview($review, $this->clock->now());
        $this->entityManager->flush();
    }

    /** Créée par une insertion qui ne peut pas échouer sur une création simultanée (voir le dépôt). */
    private function findOrCreate(User $user, Exercise $exercise): ExerciseProgress
    {
        return $this->repository->findOrCreate($user, $exercise->trackId, $exercise->id);
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
