<?php

namespace App\Entity;

use Symfony\Contracts\Translation\TranslatableInterface;

/** Traduisible : EasyAdmin affiche label() tel quel. */
enum ProgressStatus: string implements TranslatableInterface
{
    use EnumLabels;
    use EnumBadges;

    case InProgress = 'in_progress';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::InProgress => 'En cours',
            self::Completed => 'Réussi',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::InProgress => 'warning',
            self::Completed => 'success',
        };
    }
}
