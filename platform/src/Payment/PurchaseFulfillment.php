<?php

namespace App\Payment;

use App\Entity\Purchase;
use App\Entity\TrackAccess;
use App\Repository\PurchaseRepository;
use App\Repository\TrackAccessRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Ce qui suit un paiement, ou son remboursement.
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
        $purchase = $this->entityManager->wrapInTransaction(function () use ($sessionId, $amountPaid, $paymentIntentId, $invoiceId): ?Purchase {
            // Verrou sur la ligne : deux livraisons simultanées du webhook se suivent au lieu de se croiser.
            $purchase = $this->purchases->createQueryBuilder('p')
                ->andWhere('p.stripeSessionId = :session')
                ->setParameter('session', $sessionId)
                ->getQuery()
                ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                ->getOneOrNullResult()
                ?? throw new PaymentException(sprintf('Aucun achat pour la session Stripe « %s ».', $sessionId));
            \assert($purchase instanceof Purchase);

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
        foreach ($this->accesses->findByPurchase($purchase) as $access) {
            $access->revoke($now);
        }
        $this->entityManager->flush();
    }
}
