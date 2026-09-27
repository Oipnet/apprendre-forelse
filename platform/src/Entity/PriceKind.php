<?php

namespace App\Entity;

use Symfony\Contracts\Translation\TranslatableInterface;

/** Quel prix a été appliqué à un achat. Traduisible : EasyAdmin affiche label() tel quel. */
enum PriceKind: string implements TranslatableInterface
{
    use EnumLabels;

    case Normal = 'normal';
    /** Compte dans le quota du prix fondateur. */
    case Founder = 'founder';
    /** Tarif négocié d'une cohorte financée par ses apprenants. */
    case Cohort = 'cohort';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::Founder => 'Fondateur',
            self::Cohort => 'Cohorte',
        };
    }
}
