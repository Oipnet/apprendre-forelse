<?php

namespace App\Leaderboard;

/** Une ligne du classement. Ex aequo au même rang : deux premiers, puis un troisième. */
final readonly class LeaderboardEntry
{
    /** @param list<string> $tracks les parcours suivis, nommés (et « Pratique ») */
    public function __construct(
        public int $rank,
        public int $userId,
        public string $displayName,
        public int $xp,
        public int $exercises,
        /** Affiché dans le classement ; faux pour l'apprenant qui ne l'a pas choisi et voit où il se placerait. */
        public bool $listed = true,
        public array $tracks = [],
        /** Places gagnées (négatif : perdues) depuis la fin du mois précédent ; null hors du classement du mois. */
        public ?int $move = null,
        /** Absent du classement du mois précédent. */
        public bool $isNew = false,
    ) {
    }

    /** L'initiale du pseudo, pour l'avatar. */
    public function initial(): string
    {
        return mb_strtoupper(mb_substr(trim($this->displayName), 0, 1)) ?: '?';
    }
}
