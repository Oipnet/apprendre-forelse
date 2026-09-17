<?php

namespace App\Entity;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Nature d'un retour d'apprenant sur un exercice. Traduisible : EasyAdmin affiche label() tel quel. */
enum FeedbackKind: string implements TranslatableInterface
{
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

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->label();
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
