<?php

namespace App\Leaderboard;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Sur quelles réussites porte le classement : celles du mois en cours, ou toutes. La valeur paraît dans l'URL. */
enum LeaderboardPeriod: string implements TranslatableInterface
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

    /** Le libellé du bouton de la période, dans la langue de la page (label() garde le français). */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('periode.'.$this->value, [], 'classement', $locale);
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
