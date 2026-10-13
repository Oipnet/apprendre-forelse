<?php

namespace App\Leaderboard;

use App\Content\ContentRepository;
use App\Content\Exercise;
use App\Content\Track;
use App\Entity\TrackBonus;
use App\Entity\User;
use App\Entity\VerificationStatus;
use App\Repository\ExerciseProgressRepository;
use App\Repository\TrackBonusRepository;
use App\Service\TrackProgress;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Le bonus d'un parcours terminé : 10 % de l'XP de ses exercices, une fois, quand le dernier est réussi. Il compte au
 * classement comme l'XP des exercices, au mois où il tombe. Une réussite du parcours infirmée par le verificateur le
 * retire.
 */
final readonly class TrackBonuses
{
    /** La part de l'XP du parcours, en pourcentage. */
    public const int RATE = 10;

    public function __construct(
        private TrackBonusRepository $bonuses,
        private ExerciseProgressRepository $progress,
        private ContentRepository $content,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /** Le bonus du parcours : 10 % de l'XP pleine de ses exercices, arrondi. */
    public function xpFor(Track $track): int
    {
        $xp = array_sum(array_map(static fn (Exercise $exercise) => $exercise->xp, $this->content->exercisesOf($track)));

        return (int) round($xp * self::RATE / 100);
    }

    /**
     * Attribue le bonus si le parcours vient d'être terminé : tous ses exercices réussis, aucun infirmé, pas encore de
     * bonus. Ajoute son XP au compte ; à l'appelant d'enregistrer (flush).
     *
     * @return int l'XP du bonus attribué, 0 sinon
     */
    public function awardIfComplete(User $user, Track $track): int
    {
        if (null !== $this->bonuses->findOne($user, $track->id)) {
            return 0;
        }
        $progress = $this->progress->findByTrack($user, $track->id);
        if (!TrackProgress::of($track, $progress)->isComplete()) {
            return 0;
        }
        foreach ($track->exerciseIds() as $exerciseId) {
            if (VerificationStatus::Failed === ($progress[$exerciseId] ?? null)?->getVerification()) {
                return 0;
            }
        }
        $xp = $this->xpFor($track);
        if ($xp <= 0) {
            return 0;
        }
        // Daté de la dernière réussite du parcours : maintenant, sauf pour un parcours terminé avant ce bonus.
        $completedAt = array_filter(array_map(static fn (string $id) => ($progress[$id] ?? null)?->getCompletedAt(), $track->exerciseIds()));
        $this->entityManager->persist(new TrackBonus($user, $track->id, $xp, [] === $completedAt ? $this->clock->now() : max($completedAt)));
        $user->addXp($xp);

        return $xp;
    }

    /** Retire le bonus du parcours (une réussite infirmée) et son XP. À l'appelant d'enregistrer (flush). */
    public function revoke(User $user, string $trackId): void
    {
        $bonus = $this->bonuses->findOne($user, $trackId);
        if (null === $bonus) {
            return;
        }
        $user->removeXp($bonus->getXp());
        $this->entityManager->remove($bonus);
    }
}
