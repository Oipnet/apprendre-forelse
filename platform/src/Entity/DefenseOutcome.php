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

    /** Le libellé montré à l'apprenant, dans la langue de la page ; label() garde le français de l'administration. */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('defense.resultat.'.$this->value, [], 'apprentissage', $locale);
    }

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
