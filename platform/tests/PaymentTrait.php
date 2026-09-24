<?php

namespace App\Tests;

use App\Entity\TrackPricing;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/** Tarifs et webhooks Stripe simulés (signés avec STRIPE_WEBHOOK_SECRET de .env.test). */
trait PaymentTrait
{
    protected function setPrice(string $trackId, int $normal, ?int $founder = null, ?int $quota = null): TrackPricing
    {
        $pricing = (new TrackPricing($trackId))->setNormalPrice($normal)->setFounderPrice($founder)->setFounderActive(null !== $founder)->setFounderQuotaMax($quota);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($pricing);
        $entityManager->flush();

        return $pricing;
    }

    /** Envoie un événement checkout.session.completed payé, signé comme le ferait Stripe. */
    protected function sendPaidWebhook(KernelBrowser $client, string $sessionId, int $amount, string $eventId = 'evt_1', string $secret = 'whsec_test'): void
    {
        $this->sendWebhook($client, 'checkout.session.completed', [
            'id' => $sessionId,
            'object' => 'checkout.session',
            'payment_status' => 'paid',
            'amount_total' => $amount,
            'payment_intent' => 'pi_'.$sessionId,
            'invoice' => 'in_'.$sessionId,
        ], $eventId, $secret);
    }

    /**
     * Envoie un événement Stripe quelconque, signé.
     *
     * @param array<string, mixed> $object l'objet de l'événement (data.object)
     */
    protected function sendWebhook(KernelBrowser $client, string $type, array $object, string $eventId, string $secret = 'whsec_test'): void
    {
        $payload = json_encode(['id' => $eventId, 'object' => 'event', 'type' => $type, 'data' => ['object' => $object]], \JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp.'.'.$payload, $secret));

        $client->request('POST', '/paiement/stripe/webhook', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], content: $payload);
    }
}
