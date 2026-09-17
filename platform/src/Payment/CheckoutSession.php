<?php

namespace App\Payment;

/** Session de paiement ouverte chez le prestataire : on y redirige l'apprenant. */
final readonly class CheckoutSession
{
    public function __construct(
        public string $id,
        public string $url,
    ) {
    }
}
