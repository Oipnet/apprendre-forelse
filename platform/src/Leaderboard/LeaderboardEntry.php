<?php

namespace App\Leaderboard;

/** Une ligne du classement. Ex aequo au même rang : deux premiers, puis un troisième. */
final readonly class LeaderboardEntry
{
    public function __construct(
        public int $rank,
        public int $userId,
        public string $displayName,
        public int $xp,
        public int $exercises,
        /** Affiché dans le classement ; faux pour l'apprenant qui ne l'a pas choisi et voit où il se placerait. */
        public bool $listed = true,
    ) {
    }
}
