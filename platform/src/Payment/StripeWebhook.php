<?php

namespace App\Payment;

use App\Entity\StripeEvent;
use App\Repository\StripeEventRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\Webhook;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Événements Stripe : signature vérifiée, journalisés (StripeEvent), traités une fois.
 * Un événement déjà traité est ignoré ; un événement en échec est retraité à la livraison suivante,
 * ou à la main (app:stripe:rejouer).
 */
final readonly class StripeWebhook
{
    /** Paiement confirmé : immédiat (carte) ou différé (virement, prélèvement). */
    private const array PAID_EVENTS = ['checkout.session.completed', 'checkout.session.async_payment_succeeded'];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private StripeEventRepository $events,
        private PurchaseFulfillment $fulfillment,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        #[Autowire(env: 'STRIPE_WEBHOOK_SECRET')]
        private string $secret,
    ) {
    }

    /**
     * Vérifie la signature et journalise l'événement.
     *
     * @throws PaymentException signature absente ou invalide, corps illisible, webhook non configuré
     */
    public function receive(string $payload, string $signature): StripeEvent
    {
        if ('' === trim($this->secret)) {
            throw new PaymentException('Webhook Stripe non configuré (STRIPE_WEBHOOK_SECRET).');
        }
        try {
            $event = Webhook::constructEvent($payload, $signature, $this->secret);
        } catch (SignatureVerificationException|UnexpectedValueException $e) {
            throw new PaymentException('Événement Stripe refusé : '.$e->getMessage(), previous: $e);
        }

        $logged = $this->events->findOneByEventId($event->id);
        if (null !== $logged) {
            $logged->redelivered();
            $this->entityManager->flush();

            return $logged;
        }
        $logged = new StripeEvent($event->id, $event->type, $payload, $this->clock->now());
        try {
            $this->entityManager->persist($logged);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Livré deux fois au même instant : l'autre requête le traite.
            throw new PaymentException(sprintf('Événement « %s » déjà en cours de traitement.', $event->id));
        }

        return $logged;
    }

    /** Traite l'événement journalisé s'il ne l'a pas encore été. Relance l'erreur après l'avoir notée. */
    public function process(StripeEvent $event): void
    {
        if ($event->isProcessed()) {
            return;
        }
        try {
            if (\in_array($event->getType(), self::PAID_EVENTS, true)) {
                $this->handlePaidSession(json_decode($event->getPayload(), true, flags: \JSON_THROW_ON_ERROR)['data']['object'] ?? []);
            }
            $event->markProcessed($this->clock->now());
        } catch (\Throwable $e) {
            $event->markFailed($e->getMessage());
            $this->logger->error('Événement Stripe {id} en échec : {message}', ['id' => $event->getEventId(), 'message' => $e->getMessage()]);
            throw $e;
        } finally {
            if ($this->entityManager->isOpen()) {
                $this->entityManager->flush();
            }
        }
    }

    /** @param array<string, mixed> $session objet Checkout Session de l'événement */
    private function handlePaidSession(array $session): void
    {
        // « completed » arrive aussi pour un paiement différé encore en cours : on attend alors async_payment_succeeded.
        if ('paid' !== ($session['payment_status'] ?? null) || !\is_string($session['id'] ?? null)) {
            return;
        }
        $this->fulfillment->fulfill(
            $session['id'],
            isset($session['amount_total']) ? (int) $session['amount_total'] : null,
            \is_string($session['payment_intent'] ?? null) ? $session['payment_intent'] : null,
            \is_string($session['invoice'] ?? null) ? $session['invoice'] : null,
        );
    }
}
