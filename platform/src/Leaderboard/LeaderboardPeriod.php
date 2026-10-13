<?php

namespace App\Leaderboard;

/** Sur quelles réussites porte le classement : celles du mois en cours, ou toutes. La valeur paraît dans l'URL. */
enum LeaderboardPeriod: string
{
    case Month = 'mois';
    case AllTime = 'tout';

    public function label(): string
    {
        return match ($this) {
            self::Month => 'Ce mois-ci',
            self::AllTime => 'Depuis toujours',
        };
    }

    /** Le début de la période ; null pour « depuis toujours ». */
    public function since(\DateTimeImmutable $now): ?\DateTimeImmutable
    {
        return match ($this) {
            self::Month => $now->modify('first day of this month')->setTime(0, 0),
            self::AllTime => null,
        };
    }
}
