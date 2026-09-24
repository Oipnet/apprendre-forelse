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

    /** @return list<Purchase> les achats de l'apprenant, les plus récents d'abord (sessions abandonnées exclues) */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.user = :user')
            ->andWhere('p.status != :pending')
            ->setParameter('user', $user)
            ->setParameter('pending', PurchaseStatus::Pending)
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
