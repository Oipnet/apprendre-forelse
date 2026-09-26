<?php

namespace App\Payment;

use App\Entity\Purchase;
use App\Entity\PurchaseStatus;
use App\Entity\TrackAccess;
use App\Repository\PurchaseRepository;
use App\Repository\TrackAccessRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Ce qui suit un paiement, son remboursement ou sa contestation bancaire.
 *
 * Le paiement confirmé n'arrive que du webhook Stripe, jamais de la page de retour (qu'on peut ouvrir sans payer).
 * Idempotent : un webhook rejoué, ou deux événements pour la même session, n'ouvrent qu'un seul accès.
 */
final readonly class PurchaseFulfillment
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PurchaseRepository $purchases,
        private TrackAccessRepository $accesses,
        private PaymentGateway $gateway,
        private PurchaseMailer $mailer,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Passe l'achat de la session en « payé » et ouvre l'accès à vie au parcours.
     *
     * @return bool false si c'était déjà fait
     *
     * @throws PaymentException session inconnue
     */
    public function fulfill(string $sessionId, ?int $amountPaid, ?string $paymentIntentId, ?string $invoiceId = null): bool
    {
        // Session inconnue : signalée après la transaction. Une exception levée dedans fermerait l'EntityManager, et
        // StripeWebhook ne pourrait plus noter l'échec de l'événement.
        $unknownSession = false;
        $purchase = $this->entityManager->wrapInTransaction(function () use ($sessionId, $amountPaid, $paymentIntentId, $invoiceId, &$unknownSession): ?Purchase {
            // Verrou sur la ligne : deux livraisons simultanées du webhook se suivent au lieu de se croiser.
            $purchase = $this->purchases->createQueryBuilder('p')
                ->andWhere('p.stripeSessionId = :session')
                ->setParameter('session', $sessionId)
                ->getQuery()
                ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                ->getOneOrNullResult();
            if (!$purchase instanceof Purchase) {
                $unknownSession = true;

                return null;
            }

            $now = $this->clock->now();
            if (!$purchase->markPaid($now, $amountPaid, $paymentIntentId)) {
                return null;
            }
            $purchase->attachInvoice($invoiceId);
            if (null !== $purchase->getUser() && !$this->accesses->findByPurchase($purchase)) {
                $this->entityManager->persist(TrackAccess::purchased($purchase, $now));
            }

            return $purchase;
        });
        if ($unknownSession) {
            throw new PaymentException(sprintf('Aucun achat pour la session Stripe « %s ».', $sessionId));
        }
        if (null === $purchase) {
            return false;
        }

        if (null !== $purchase->getStripePaymentIntentId()) {
            $purchase->setReceiptUrl($this->gateway->receiptUrl($purchase->getStripePaymentIntentId()));
            $this->entityManager->flush();
        }
        $this->mailer->sendConfirmation($purchase);

        return true;
    }

    /**
     * Paiement différé refusé : l'achat en attente est abandonné. Sans cela, il resterait « en attente » pour de bon,
     * et l'apprenant qui réessaie serait renvoyé sur la page de remerciement au lieu de pouvoir payer.
     */
    public function paymentFailed(string $sessionId): void
    {
        $this->purchases->findOneBySession($sessionId)?->markAbandoned();
        $this->entityManager->flush();
    }

    /**
     * Rembourse l'achat chez Stripe, le note et révoque l'accès qu'il avait ouvert (la progression reste).
     *
     * @throws PaymentException
     */
    public function refund(Purchase $purchase): void
    {
        if (!$purchase->isPaid()) {
            throw new PaymentException('Seul un achat payé peut être remboursé.');
        }
        $paymentIntentId = $purchase->getStripePaymentIntentId() ?? throw new PaymentException('Cet achat n\'a pas de paiement Stripe à rembourser.');

        $refundId = $this->gateway->refund($paymentIntentId);
        $now = $this->clock->now();
        $purchase->markRefunded($now, $refundId);
        $this->revokeAccess($purchase, $now);
        $this->entityManager->flush();
    }

    /**
     * Remboursement total fait ailleurs que dans l'administration (tableau de bord Stripe) : noté, accès révoqué.
     * Sans effet sur un achat déjà remboursé, dont celui que refund() vient de faire.
     */
    public function refundedAtStripe(string $paymentIntentId, ?string $refundId): void
    {
        $purchase = $this->purchases->findOneByPaymentIntent($paymentIntentId);
        if (null === $purchase || PurchaseStatus::Refunded === $purchase->getStatus()) {
            return;
        }
        $now = $this->clock->now();
        $purchase->markRefunded($now, $refundId);
        $this->revokeAccess($purchase, $now);
        $this->entityManager->flush();
    }

    /** Contestation bancaire ouverte : l'accès est révoqué le temps qu'elle se règle, et le reste si elle est perdue. */
    public function disputed(string $paymentIntentId): void
    {
        $purchase = $this->purchases->findOneByPaymentIntent($paymentIntentId);
        if (null === $purchase || !$purchase->markDisputed()) {
            return;
        }
        $this->revokeAccess($purchase, $this->clock->now());
        $this->entityManager->flush();
    }

    /** Contestation gagnée : le paiement reste acquis, l'accès se rouvre. */
    public function disputeWon(string $paymentIntentId): void
    {
        $purchase = $this->purchases->findOneByPaymentIntent($paymentIntentId);
        if (null === $purchase || !$purchase->markDisputeWon()) {
            return;
        }
        foreach ($this->accesses->findByPurchase($purchase) as $access) {
            $access->restore();
        }
        $this->entityManager->flush();
    }

    /** L'accès ouvert par l'achat se termine ; la progression reste. */
    private function revokeAccess(Purchase $purchase, \DateTimeImmutable $now): void
    {
        foreach ($this->accesses->findByPurchase($purchase) as $access) {
            $access->revoke($now);
        }
    }
}
