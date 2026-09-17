<?php

namespace App\Entity;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Quel prix a été appliqué à un achat. Traduisible : EasyAdmin affiche label() tel quel. */
enum PriceKind: string implements TranslatableInterface
{
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

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->label();
    }
}
