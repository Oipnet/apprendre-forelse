<?php

namespace App\Entity;

use Symfony\Contracts\Translation\TranslatableInterface;

/** Nature d'un retour d'apprenant sur un exercice. Traduisible : EasyAdmin affiche label() tel quel. */
enum FeedbackKind: string implements TranslatableInterface
{
    use EnumLabels;
    use EnumBadges;

    case Bug = 'bug';
    case Unclear = 'unclear';
    case TooEasy = 'too-easy';
    case TooHard = 'too-hard';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Bug => 'Bogue',
            self::Unclear => 'Pas clair',
            self::TooEasy => 'Trop facile',
            self::TooHard => 'Trop difficile',
            self::Other => 'Autre',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function badge(): string
    {
        return match ($this) {
            self::Bug => 'danger',
            self::Unclear => 'warning',
            self::TooEasy => 'info',
            self::TooHard => 'warning',
            self::Other => 'secondary',
        };
    }
}
