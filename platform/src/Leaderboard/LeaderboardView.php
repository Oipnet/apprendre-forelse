<?php

namespace App\Leaderboard;

/** Le classement d'une période, tel que la page l'affiche. */
final readonly class LeaderboardView
{
    /**
     * @param list<LeaderboardEntry> $entries les premiers, dans l'ordre
     */
    public function __construct(
        public LeaderboardPeriod $period,
        public ?\DateTimeImmutable $since,
        public array $entries,
        /** Les apprenants classés sur la période, au-delà des premiers affichés. */
        public int $total,
        /** La ligne de l'apprenant qui regarde, même hors des premiers, ou s'il n'a pas choisi d'y paraître. */
        public ?LeaderboardEntry $viewer,
    ) {
    }
}
