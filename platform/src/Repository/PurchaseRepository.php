<?php

namespace App\Repository;

use App\Entity\PriceKind;
use App\Entity\Purchase;
use App\Entity\PurchaseStatus;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Purchase>
 */
class PurchaseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Purchase::class);
    }

    /** Achats payés au prix fondateur : ce que compte le quota (un remboursement libère la place). */
    public function countFounderSales(string $trackId): int
    {
        return $this->count(['trackId' => $trackId, 'priceKind' => PriceKind::Founder, 'status' => PurchaseStatus::Paid]);
    }

    public function findOneBySession(string $sessionId): ?Purchase
    {
        return $this->findOneBy(['stripeSessionId' => $sessionId]);
    }

    public function findOneByPaymentIntent(string $paymentIntentId): ?Purchase
    {
        return $this->findOneBy(['stripePaymentIntentId' => $paymentIntentId]);
    }

    /** L'achat de ce parcours dont la session Stripe est peut-être encore ouverte (un seul, voir PurchaseCheckout). */
    public function findPendingCheckout(User $user, string $trackId): ?Purchase
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.user = :user')
            ->andWhere('p.trackId = :track')
            ->andWhere('p.status = :pending')
            ->andWhere('p.stripeSessionId IS NOT NULL')
            ->setParameter('user', $user)
            ->setParameter('track', $trackId)
            ->setParameter('pending', PurchaseStatus::Pending)
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<Purchase> les achats de l'apprenant, les plus récents d'abord (sessions abandonnées exclues) */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.user = :user')
            ->andWhere('p.status NOT IN (:unpaid)')
            ->setParameter('user', $user)
            ->setParameter('unpaid', [PurchaseStatus::Pending, PurchaseStatus::Abandoned])
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
