<?php

namespace App\Entity;

use Symfony\Contracts\Translation\TranslatableInterface;

/** Qui paie les parcours d'une cohorte. Traduisible : EasyAdmin affiche label() tel quel. */
enum FundingMode: string implements TranslatableInterface
{
    use EnumLabels;
    use EnumBadges;

    /** L'établissement paie (devis) : chaque apprenant reçoit l'accès aux parcours de la cohorte, à ses dates. */
    case Institution = 'institution';
    /** Chaque apprenant achète lui-même, au tarif de la cohorte s'il est fixé. */
    case Learners = 'learners';

    public function label(): string
    {
        return match ($this) {
            self::Institution => 'Établissement',
            self::Learners => 'Apprenants',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Institution => 'info',
            self::Learners => 'secondary',
        };
    }
}
