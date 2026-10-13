<?php

namespace App\Repository;

use App\Entity\Cohort;
use App\Entity\Defense;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Defense>
 */
class DefenseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Defense::class);
    }

    /** @return list<Defense> les créneaux de la cohorte, par date */
    public function findByCohort(Cohort $cohort): array
    {
        return $this->findBy(['cohort' => $cohort], ['startsAt' => 'ASC', 'id' => 'ASC']);
    }

    /** La cohorte a ouvert au moins un créneau sur ce parcours. */
    public function cohortHasSlots(Cohort $cohort, string $trackId): bool
    {
        return null !== $this->findOneBy(['cohort' => $cohort, 'trackId' => $trackId]);
    }

    /** @return list<Defense> les créneaux libres et à venir de la cohorte sur ce parcours, par date */
    public function findFreeSlots(Cohort $cohort, string $trackId, \DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.cohort = :cohort')
            ->andWhere('d.trackId = :track')
            ->andWhere('d.bookedAt IS NULL')
            ->andWhere('d.startsAt > :now')
            ->setParameter('cohort', $cohort)
            ->setParameter('track', $trackId)
            ->setParameter('now', $now)
            ->orderBy('d.startsAt', 'ASC')
            ->addOrderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<Defense> les passages de l'apprenant sur ce parcours, du plus récent au plus ancien */
    public function findByLearner(User $learner, string $trackId): array
    {
        return $this->findBy(['learner' => $learner, 'trackId' => $trackId], ['startsAt' => 'DESC', 'id' => 'DESC']);
    }
}
