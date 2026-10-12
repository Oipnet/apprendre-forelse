<?php

namespace App\Leaderboard;

use App\Entity\User;
use App\Repository\ExerciseProgressRepository;
use Psr\Clock\ClockInterface;

/**
 * Le classement des apprenants à l'XP : ceux qui ont choisi d'y paraître (case de « Mon compte »), sous leur pseudo.
 * L'XP tient déjà compte des indices et de la solution consultée ; une réussite infirmée par le verificateur (voir
 * App\Verification) n'y compte plus.
 */
final readonly class Leaderboard
{
    /** Lignes affichées : au-delà, l'apprenant voit seulement son propre rang. */
    public const int TOP = 50;

    public function __construct(
        private ExerciseProgressRepository $progress,
        private ClockInterface $clock,
    ) {
    }

    public function of(LeaderboardPeriod $period, ?User $viewer = null): LeaderboardView
    {
        $since = $period->since($this->clock->now());
        $listed = [];
        $own = null;
        foreach ($this->progress->leaderboardRows($since, $viewer) as $row) {
            if (null !== $viewer && $row['userId'] === $viewer->getId() && !$viewer->isLeaderboardOptIn()) {
                $own = $row;
            } else {
                $listed[] = $row;
            }
        }

        $entries = [];
        $previous = null;
        foreach ($listed as $index => $row) {
            // Classement « sportif » : un ex aequo partage le rang, le suivant prend le rang de sa position.
            $rank = null !== $previous && $previous->xp === $row['xp'] ? $previous->rank : $index + 1;
            $entries[] = $previous = new LeaderboardEntry($rank, $row['userId'], $row['displayName'], $row['xp'], $row['exercises']);
        }

        $viewerEntry = null;
        if (null !== $own) {
            $ahead = \count(array_filter($listed, static fn (array $row) => $row['xp'] > $own['xp']));
            $viewerEntry = new LeaderboardEntry($ahead + 1, $own['userId'], $own['displayName'], $own['xp'], $own['exercises'], false);
        } elseif (null !== $viewer) {
            foreach ($entries as $entry) {
                if ($entry->userId === $viewer->getId()) {
                    $viewerEntry = $entry;
                    break;
                }
            }
        }

        return new LeaderboardView($period, $since, \array_slice($entries, 0, self::TOP), \count($entries), $viewerEntry);
    }
}
