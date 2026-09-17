<?php

namespace App\Repository;

use App\Entity\AccessSource;
use App\Entity\Cohort;
use App\Entity\Purchase;
use App\Entity\TrackAccess;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrackAccess>
 */
class TrackAccessRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrackAccess::class);
    }

    /** @return list<string> les parcours auxquels l'apprenant a un accès actif */
    public function findActiveTrackIds(User $user, \DateTimeImmutable $now): array
    {
        if (null === $user->getId()) {
            return [];
        }

        return array_values(array_unique(array_column($this->createQueryBuilder('a')
            ->select('a.trackId')
            ->andWhere('a.user = :user')
            ->andWhere('a.startsAt <= :now')
            ->andWhere('a.endsAt IS NULL OR a.endsAt > :now')
            ->setParameter('user', $user)
            ->setParameter('now', $now)
            ->getQuery()
            ->getScalarResult(), 'trackId')));
    }

    /** @return list<TrackAccess> tous les accès de l'apprenant, les plus récents d'abord */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    /** L'accès ouvert par cette cohorte à ce parcours, actif ou non. */
    public function findCohortAccess(User $user, string $trackId, Cohort $cohort): ?TrackAccess
    {
        return $this->findOneBy(['user' => $user, 'trackId' => $trackId, 'cohort' => $cohort, 'source' => AccessSource::Cohort]);
    }

    /** @return list<TrackAccess> les accès ouverts par la cohorte, à tous ses apprenants passés et présents */
    public function findByCohort(Cohort $cohort): array
    {
        return $this->findBy(['cohort' => $cohort, 'source' => AccessSource::Cohort]);
    }

    /** @return list<TrackAccess> */
    public function findByPurchase(Purchase $purchase): array
    {
        return $this->findBy(['purchase' => $purchase]);
    }

    public function hasAnyAccess(User $user, string $trackId): bool
    {
        return null !== $this->findOneBy(['user' => $user, 'trackId' => $trackId]);
    }
}
