<?php

namespace App\Entity;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Le niveau atteint sur une ligne de la grille d'évaluation (un chapitre du parcours). */
enum DefenseLevel: string implements TranslatableInterface
{
    use EnumLabels;

    case Acquired = 'acquired';
    case InProgress = 'in_progress';
    case NotAcquired = 'not_acquired';

    public function label(): string
    {
        return match ($this) {
            self::Acquired => 'Acquis',
            self::InProgress => 'En cours d\'acquisition',
            self::NotAcquired => 'Non acquis',
        };
    }

    /** Le libellé traduit (emails, pages, administration) ; label() reste le français des exports et de l'agenda. */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('enum.defense_level.'.$this->value, [], 'emails', $locale);
    }
}
