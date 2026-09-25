<?php

namespace App\Repository;

use App\Entity\PriceKind;
use App\Entity\Purchase;
use App\Entity\PurchaseStatus;
use App\Entity\User;
use App\Payment\CheckoutSession;
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

    /**
     * Places prises au prix fondateur, par parcours, en une requête pour tous les parcours (l'accueil affiche celles
     * de chacun) : les achats payés (un remboursement libère la place), et les achats en attente dont la session
     * Stripe peut encore être payée. Le prix est figé dès l'achat en attente : sans eux, N paiements lancés ensemble
     * sur la dernière place l'obtenaient tous. L'achat en attente de $except ne compte pas : il garde sa place, et son
     * prix, s'il revient sur la page de paiement.
     *
     * @return array<string, int> par parcours ; un parcours sans place prise est absent
     */
    public function founderSalesByTrack(\DateTimeImmutable $now, ?User $except = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('p.trackId AS track, COUNT(p.id) AS sales')
            ->groupBy('p.trackId')
            ->andWhere('p.priceKind = :founder')
            ->andWhere('p.status = :paid OR (p.status = :pending AND p.createdAt > :reservedSince'.(null !== $except ? ' AND (p.user IS NULL OR p.user != :except)' : '').')')
            ->setParameter('founder', PriceKind::Founder)
            ->setParameter('paid', PurchaseStatus::Paid)
            ->setParameter('pending', PurchaseStatus::Pending)
            // La session est créée juste après l'achat : quelques minutes de marge sur sa durée de vie.
            ->setParameter('reservedSince', $now->modify(sprintf('-%d seconds', CheckoutSession::LIFETIME + 300)));
        if (null !== $except) {
            $qb->setParameter('except', $except);
        }

        return array_map('intval', array_column($qb->getQuery()->getArrayResult(), 'sales', 'track'));
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
