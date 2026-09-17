<?php

namespace App\Repository;

use App\Entity\Feedback;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Feedback>
 */
class FeedbackRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Feedback::class);
    }

    /**
     * Les retours d'une cohorte désignée par son code (tous si null), du plus ancien au plus récent.
     *
     * @return list<Feedback>
     */
    public function findByCohort(?string $code): array
    {
        $query = $this->createQueryBuilder('f')->join('f.user', 'u')->addSelect('u')->leftJoin('u.cohort', 'c')->addSelect('c')->orderBy('f.createdAt', 'ASC');
        if (null !== $code) {
            $query->andWhere('c.code = :code')->setParameter('code', $code);
        }

        return $query->getQuery()->getResult();
    }
}
