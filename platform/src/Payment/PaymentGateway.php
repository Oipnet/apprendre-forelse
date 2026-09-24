<?php

namespace App\Payment;

use App\Entity\Purchase;

/**
 * Le prestataire de paiement, vu de la plateforme : Stripe en vrai (StripeGateway), une passerelle factice en test.
 * La confirmation d'un paiement n'arrive jamais par ici : seulement par le webhook (StripeWebhookController).
 */
interface PaymentGateway
{
    /** Des clés sont renseignées : le bouton d'achat est actif. */
    public function isConfigured(): bool;

    /** @throws PaymentException */
    public function createCheckoutSession(Purchase $purchase, string $productName, string $successUrl, string $cancelUrl): CheckoutSession;

    /** @throws PaymentException */
    public function retrieveCheckoutSession(string $sessionId): CheckoutSession;

    /**
     * Ferme une session encore ouverte : plus rien ne peut y être payé. Échoue si elle vient d'être payée.
     *
     * @throws PaymentException
     */
    public function expireCheckoutSession(string $sessionId): void;

    /** Lien vers le reçu du paiement, s'il existe déjà. */
    public function receiptUrl(string $paymentIntentId): ?string;

    /**
     * Lien vers le PDF de la facture. Demandé au moment du téléchargement : les liens de Stripe expirent.
     *
     * @throws PaymentException
     */
    public function invoicePdfUrl(string $invoiceId): ?string;

    /**
     * Rembourse intégralement le paiement.
     *
     * @return string identifiant du remboursement
     *
     * @throws PaymentException
     */
    public function refund(string $paymentIntentId): string;
}
