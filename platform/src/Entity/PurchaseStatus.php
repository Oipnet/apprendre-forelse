<?php

namespace App\Entity;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Traduisible : EasyAdmin affiche label() tel quel. */
enum PurchaseStatus: string implements TranslatableInterface
{
    /** Session Stripe ouverte, paiement pas encore confirmé par le webhook. */
    case Pending = 'pending';
    /**
     * Session Stripe expirée, remplacée par un nouvel achat (autre onglet, prix changé), ou paiement différé refusé :
     * rien n'a été payé.
     */
    case Abandoned = 'abandoned';
    case Paid = 'paid';
    case Refunded = 'refunded';
    /** Contestation bancaire ouverte, ou perdue : l'accès est révoqué. Gagnée, l'achat redevient payé. */
    case Disputed = 'disputed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Abandoned => 'Abandonné',
            self::Paid => 'Payé',
            self::Refunded => 'Remboursé',
            self::Disputed => 'Contesté',
        };
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->label();
    }
}
