<?php

namespace App\Payment;

/** Session de paiement chez le prestataire : on y redirige l'apprenant tant qu'elle est ouverte. */
final readonly class CheckoutSession
{
    public const string OPEN = 'open';
    /** Payée (ou paiement différé lancé) : le webhook confirmera. */
    public const string COMPLETE = 'complete';
    /** Expirée ou close : plus rien ne peut y être payé. */
    public const string EXPIRED = 'expired';

    /**
     * Durée pendant laquelle une session reste payable (Stripe accepte de 30 minutes à 24 heures). Un achat en attente
     * réserve sa place au prix fondateur le temps de sa session, et la rend ensuite (PurchaseRepository::countFounderSales).
     */
    public const int LIFETIME = 3600;

    public function __construct(
        public string $id,
        /** Vide une fois la session fermée : Stripe ne la sert plus. */
        public string $url,
        public string $status = self::OPEN,
    ) {
    }

    public function isOpen(): bool
    {
        return self::OPEN === $this->status;
    }

    public function isComplete(): bool
    {
        return self::COMPLETE === $this->status;
    }
}
