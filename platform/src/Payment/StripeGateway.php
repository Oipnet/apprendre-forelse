<?php

namespace App\Payment;

use App\Entity\Purchase;
use Stripe\Charge;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Stripe Checkout en paiement unique, avec Stripe Tax : prix TTC (tax_behavior inclusive), TVA du pays du client,
 * adresse de facturation demandée pour la déterminer. Chaque paiement donne une facture Stripe (numérotation et TVA
 * tenues par Stripe) ; un client professionnel peut y faire figurer sa raison sociale et son numéro de TVA.
 */
#[AsAlias(PaymentGateway::class)]
final class StripeGateway implements PaymentGateway
{
    private ?StripeClient $client = null;

    public function __construct(
        #[Autowire(env: 'STRIPE_SECRET_KEY')]
        private readonly string $secretKey,
        #[Autowire(env: 'STRIPE_TAX_CODE')]
        private readonly string $taxCode,
        #[Autowire('%kernel.environment%')]
        private readonly string $kernelEnvironment,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== trim($this->secretKey);
    }

    public function createCheckoutSession(Purchase $purchase, string $productName, string $successUrl, string $cancelUrl): CheckoutSession
    {
        $metadata = ['purchase_id' => (string) $purchase->getId(), 'track_id' => $purchase->getTrackId()];
        try {
            $session = $this->client()->checkout->sessions->create([
                'mode' => 'payment',
                'locale' => 'fr',
                'customer_email' => $purchase->getCustomerEmail(),
                'client_reference_id' => (string) $purchase->getId(),
                'metadata' => $metadata,
                'payment_intent_data' => ['metadata' => $metadata, 'receipt_email' => $purchase->getCustomerEmail()],
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => 'eur',
                        'unit_amount' => $purchase->getPrice(),
                        // Les prix affichés sont TTC : Stripe en déduit la TVA du pays du client.
                        'tax_behavior' => 'inclusive',
                        'product_data' => ['name' => $productName, 'tax_code' => $this->taxCode],
                    ],
                ]],
                'automatic_tax' => ['enabled' => true],
                'billing_address_collection' => 'required',
                // Facture après paiement (Stripe crée alors un client) ; montant TTC avec la TVA détaillée.
                'invoice_creation' => [
                    'enabled' => true,
                    'invoice_data' => [
                        'metadata' => $metadata,
                        'rendering_options' => ['amount_tax_display' => 'include_inclusive_tax'],
                    ],
                ],
                'tax_id_collection' => ['enabled' => true],
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
            ], ['idempotency_key' => 'purchase-'.$purchase->getId()]);
        } catch (ApiErrorException $e) {
            throw new PaymentException('Le paiement n\'a pas pu démarrer : '.$e->getMessage(), previous: $e);
        }

        return new CheckoutSession($session->id, (string) $session->url);
    }

    public function receiptUrl(string $paymentIntentId): ?string
    {
        try {
            $charge = $this->client()->paymentIntents->retrieve($paymentIntentId, ['expand' => ['latest_charge']])->latest_charge;
        } catch (ApiErrorException) {
            return null;
        }

        return $charge instanceof Charge ? $charge->receipt_url : null;
    }

    public function invoicePdfUrl(string $invoiceId): ?string
    {
        try {
            return $this->client()->invoices->retrieve($invoiceId)->invoice_pdf;
        } catch (ApiErrorException $e) {
            throw new PaymentException('Facture introuvable chez Stripe : '.$e->getMessage(), previous: $e);
        }
    }

    public function refund(string $paymentIntentId): string
    {
        try {
            return $this->client()->refunds->create(['payment_intent' => $paymentIntentId], ['idempotency_key' => 'refund-'.$paymentIntentId])->id;
        } catch (ApiErrorException $e) {
            throw new PaymentException('Stripe a refusé le remboursement : '.$e->getMessage(), previous: $e);
        }
    }

    private function client(): StripeClient
    {
        if (!$this->isConfigured()) {
            throw new PaymentException('Le paiement n\'est pas configuré sur cette plateforme (STRIPE_SECRET_KEY).');
        }
        // Mode test par défaut : une clé réelle hors production débiterait de vraies cartes pendant un essai.
        if (str_starts_with($this->secretKey, 'sk_live_') && 'prod' !== $this->kernelEnvironment) {
            throw new PaymentException('Clé Stripe réelle (sk_live_) refusée hors production : utilisez une clé de test.');
        }

        return $this->client ??= new StripeClient($this->secretKey);
    }
}
