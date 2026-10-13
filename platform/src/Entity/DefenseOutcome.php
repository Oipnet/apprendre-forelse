<?php

namespace App\Entity;

use Symfony\Contracts\Translation\TranslatableInterface;

/** Le résultat d'une soutenance, une fois évaluée. */
enum DefenseOutcome: string implements TranslatableInterface
{
    use EnumLabels;
    use EnumBadges;

    case Passed = 'passed';
    /** L'apprenant peut réserver un nouveau créneau. */
    case Retake = 'retake';

    public function label(): string
    {
        return match ($this) {
            self::Passed => 'Soutenance validée',
            self::Retake => 'À repasser',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Passed => 'success',
            self::Retake => 'warning',
        };
    }
}
