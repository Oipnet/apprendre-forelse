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
            $object = json_decode($event->getPayload(), true, flags: \JSON_THROW_ON_ERROR)['data']['object'] ?? [];
            match (true) {
                \in_array($event->getType(), self::PAID_EVENTS, true) => $this->handlePaidSession($object),
                'checkout.session.async_payment_failed' === $event->getType() => $this->handleFailedSession($object),
                'charge.refunded' === $event->getType() => $this->handleRefundedCharge($object),
                'charge.dispute.created' === $event->getType() => $this->handleDispute($object, closed: false),
                'charge.dispute.closed' === $event->getType() => $this->handleDispute($object, closed: true),
                default => null,
            };
            $event->markProcessed($this->clock->now());
        } catch (\Throwable $e) {
            $event->markFailed($e->getMessage());
            $this->saveFailure($event);
            $this->logger->error('Événement Stripe {id} en échec : {message}', ['id' => $event->getEventId(), 'message' => $e->getMessage()]);
            throw $e;
        } finally {
            if ($this->entityManager->isOpen()) {
                $this->entityManager->flush();
            }
        }
    }

    /**
     * L'erreur est écrite par une requête à part : l'exception a pu fermer l'EntityManager (levée dans une transaction),
     * et le flush du finally n'aurait alors pas lieu. Sans elle, ni le message ni l'échec ne resteraient en base.
     */
    private function saveFailure(StripeEvent $event): void
    {
        $metadata = $this->entityManager->getClassMetadata(StripeEvent::class);
        $this->entityManager->getConnection()->update(
            $metadata->getTableName(),
            [$metadata->getColumnName('error') => $event->getError()],
            [$metadata->getColumnName('id') => $event->getId()],
        );
    }

    /**
     * Remboursement total (un remboursement partiel laisse l'accès ouvert). Celui de l'administration arrive aussi
     * par ici, après coup : l'achat est déjà noté remboursé, rien ne change.
     *
     * @param array<string, mixed> $charge objet Charge de l'événement
     */
    private function handleRefundedCharge(array $charge): void
    {
        if (true !== ($charge['refunded'] ?? null) || !\is_string($charge['payment_intent'] ?? null)) {
            return;
        }
        $refundId = $charge['refunds']['data'][0]['id'] ?? null;
        $this->fulfillment->refundedAtStripe($charge['payment_intent'], \is_string($refundId) ? $refundId : null);
    }

    /**
     * Contestation bancaire : ouverte, l'accès est révoqué ; close et gagnée, il se rouvre ; perdue, il reste fermé.
     *
     * @param array<string, mixed> $dispute objet Dispute de l'événement
     */
    private function handleDispute(array $dispute, bool $closed): void
    {
        if (!\is_string($dispute['payment_intent'] ?? null)) {
            return;
        }
        if (!$closed) {
            $this->fulfillment->disputed($dispute['payment_intent']);
        } elseif ('won' === ($dispute['status'] ?? null)) {
            $this->fulfillment->disputeWon($dispute['payment_intent']);
        }
    }

    /**
     * Paiement différé refusé (prélèvement rejeté…) : la session est close chez Stripe, l'achat est abandonné pour
     * que l'apprenant puisse payer autrement.
     *
     * @param array<string, mixed> $session objet Checkout Session de l'événement
     */
    private function handleFailedSession(array $session): void
    {
        if (\is_string($session['id'] ?? null)) {
            $this->fulfillment->paymentFailed($session['id']);
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
