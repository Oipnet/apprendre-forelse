<?php

namespace App\Tests\Payment;

use App\Entity\Purchase;
use App\Payment\CheckoutSession;
use App\Payment\PaymentException;
use App\Payment\PaymentGateway;

/**
 * Passerelle de paiement des tests (config/services.yaml, when@test) : aucune requête vers Stripe.
 * L'état est statique, le client de test redémarrant le kernel à chaque requête : appeler reset() dans setUp().
 */
final class FakePaymentGateway implements PaymentGateway
{
    /** @var list<array{purchaseId: int|null, price: int, productName: string, successUrl: string}> */
    public static array $sessions = [];
    /** @var array<string, string> état de chaque session créée (CheckoutSession::OPEN par défaut), par identifiant */
    public static array $statuses = [];
    /** @var list<string> sessions closes par la plateforme */
    public static array $expired = [];
    /** @var list<string> payment intents remboursés */
    public static array $refunds = [];
    public static bool $failing = false;
    /** false : comme une instance sans clés Stripe. */
    public static bool $configured = true;

    public static function reset(): void
    {
        self::$sessions = self::$refunds = self::$statuses = self::$expired = [];
        self::$failing = false;
        self::$configured = true;
    }

    public function isConfigured(): bool
    {
        return self::$configured;
    }

    public function createCheckoutSession(Purchase $purchase, string $productName, string $successUrl, string $cancelUrl): CheckoutSession
    {
        if (self::$failing) {
            throw new PaymentException('Stripe ne répond pas.');
        }
        self::$sessions[] = ['purchaseId' => $purchase->getId(), 'price' => $purchase->getPrice(), 'productName' => $productName, 'successUrl' => $successUrl];

        self::$statuses['cs_test_'.$purchase->getId()] = CheckoutSession::OPEN;

        return new CheckoutSession('cs_test_'.$purchase->getId(), 'https://checkout.stripe.test/c/pay/cs_test_'.$purchase->getId());
    }

    public function retrieveCheckoutSession(string $sessionId): CheckoutSession
    {
        if (self::$failing) {
            throw new PaymentException('Stripe ne répond pas.');
        }
        $status = self::$statuses[$sessionId] ?? throw new PaymentException(sprintf('Session « %s » inconnue.', $sessionId));

        return new CheckoutSession($sessionId, CheckoutSession::OPEN === $status ? 'https://checkout.stripe.test/c/pay/'.$sessionId : '', $status);
    }

    /** Comme Stripe : seule une session ouverte se ferme. */
    public function expireCheckoutSession(string $sessionId): void
    {
        if (CheckoutSession::OPEN !== (self::$statuses[$sessionId] ?? null)) {
            throw new PaymentException('Seule une session ouverte peut expirer.');
        }
        self::$statuses[$sessionId] = CheckoutSession::EXPIRED;
        self::$expired[] = $sessionId;
    }

    public function receiptUrl(string $paymentIntentId): ?string
    {
        return 'https://pay.stripe.test/receipts/'.$paymentIntentId;
    }

    public function invoicePdfUrl(string $invoiceId): ?string
    {
        return 'https://pay.stripe.test/invoice/'.$invoiceId.'/pdf';
    }

    public function refund(string $paymentIntentId): string
    {
        self::$refunds[] = $paymentIntentId;

        return 're_'.$paymentIntentId;
    }
}
