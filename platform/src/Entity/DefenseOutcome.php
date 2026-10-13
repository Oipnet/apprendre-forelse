<?php

namespace App\Entity;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

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

    /** Le libellé traduit (emails, pages, administration) ; label() reste le français des exports et de l'agenda. */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enum.defense_outcome.'.$this->value, [], 'emails', $locale);
    }

    public function badge(): string
    {
        return match ($this) {
            self::Passed => 'success',
            self::Retake => 'warning',
        };
    }
}
