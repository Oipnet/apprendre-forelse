<?php

namespace App\Entity;

use Symfony\Contracts\Translation\TranslatableInterface;

/** D'où vient l'accès d'un apprenant à un parcours. Traduisible : EasyAdmin affiche label() tel quel. */
enum AccessSource: string implements TranslatableInterface
{
    use EnumLabels;
    use EnumBadges;

    /** Acheté par l'apprenant (Stripe) : à vie, sauf remboursement. */
    case Purchase = 'purchase';
    /** Ouvert par sa cohorte financée par l'établissement, aux dates de la cohorte. */
    case Cohort = 'cohort';
    /** Offert par un administrateur, avec ou sans date de fin. */
    case Gift = 'gift';

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'Achat',
            self::Cohort => 'Cohorte',
            self::Gift => 'Offert',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Purchase => 'success',
            self::Cohort => 'info',
            self::Gift => 'secondary',
        };
    }
}
