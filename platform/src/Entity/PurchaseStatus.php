<?php

namespace App\Entity;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Traduisible : EasyAdmin affiche label() tel quel. */
enum PurchaseStatus: string implements TranslatableInterface
{
    /** Session Stripe ouverte, paiement pas encore confirmé par le webhook. */
    case Pending = 'pending';
    case Paid = 'paid';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Paid => 'Payé',
            self::Refunded => 'Remboursé',
        };
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->label();
    }
}
