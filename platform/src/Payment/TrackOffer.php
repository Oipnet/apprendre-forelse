<?php

namespace App\Payment;

/** Ce qu'une page montre d'un parcours à un apprenant : son prix, et « Acheter » ou « Continuer ». */
final readonly class TrackOffer
{
    public function __construct(
        public PriceQuote $quote,
        /** Tout le parcours est ouvert à cet apprenant (accès, gratuité, rôle). */
        public bool $fullAccess,
        /** Le paiement est configuré sur l'instance, et les CGV sont complètes (vendeur, médiateur). */
        public bool $paymentsEnabled,
    ) {
    }

    /** Parcours payant, pas encore ouvert à cet apprenant. */
    public function isLocked(): bool
    {
        return !$this->quote->isFree() && !$this->fullAccess;
    }

    /** Le bouton « Acheter » a lieu d'être : parcours fermé, et paiement configuré sur l'instance. */
    public function isPurchasable(): bool
    {
        return $this->isLocked() && $this->paymentsEnabled;
    }

    /** Parcours payant qu'on ne peut pas encore acheter (clés Stripe absentes, CGV incomplètes) : « Bientôt disponible ». */
    public function isComingSoon(): bool
    {
        return $this->isLocked() && !$this->paymentsEnabled;
    }
}
