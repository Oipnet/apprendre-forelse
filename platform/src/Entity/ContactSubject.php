<?php

namespace App\Entity;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Objet d'un message envoyé par le formulaire de contact. Traduisible : EasyAdmin affiche label() tel quel. */
enum ContactSubject: string implements TranslatableInterface
{
    case Question = 'question';
    case Order = 'commande';
    case Bug = 'probleme';
    case Organization = 'ecole';
    case Other = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::Question => 'Une question sur les parcours',
            self::Order => 'Un achat, une facture, un remboursement',
            self::Bug => 'Un problème sur le site',
            self::Organization => 'Former une classe ou une équipe',
            self::Other => 'Autre chose',
        };
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->label();
    }
}
