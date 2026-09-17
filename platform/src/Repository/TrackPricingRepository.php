<?php

namespace App\Repository;

use App\Entity\TrackPricing;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrackPricing>
 */
class TrackPricingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrackPricing::class);
    }

    public function findOneByTrack(string $trackId): ?TrackPricing
    {
        return $this->findOneBy(['trackId' => $trackId]);
    }

    /** Un parcours sans tarif, ou à 0 €, est gratuit. */
    public function isPaid(string $trackId): bool
    {
        return !($this->findOneByTrack($trackId)?->isFree() ?? true);
    }
}
