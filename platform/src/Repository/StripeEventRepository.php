<?php

namespace App\Repository;

use App\Entity\StripeEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StripeEvent>
 */
class StripeEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StripeEvent::class);
    }

    public function findOneByEventId(string $eventId): ?StripeEvent
    {
        return $this->findOneBy(['eventId' => $eventId]);
    }
}
