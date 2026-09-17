<?php

namespace App\Repository;

use App\Entity\Cohort;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Cohort>
 */
class CohortRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Cohort::class);
    }

    public function findOneByCode(string $code): ?Cohort
    {
        return $this->findOneBy(['code' => strtolower(trim($code))]);
    }

    /** Cohorte qui accepte encore des inscriptions avec ce code. */
    public function findActiveByCode(string $code): ?Cohort
    {
        return $this->findOneBy(['code' => strtolower(trim($code)), 'active' => true]);
    }

    /** @return list<Cohort> */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['name' => 'ASC']);
    }

    /** @return list<Cohort> les cohortes dont ce compte est chef, par nom */
    public function findByChef(User $chef): array
    {
        return $this->createQueryBuilder('c')
            ->innerJoin('c.chefs', 'chef')
            ->andWhere('chef = :chef')
            ->setParameter('chef', $chef)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<Cohort> $cohorts
     *
     * @return array<int, int> nombre d'apprenants par identifiant de cohorte (absent : aucun)
     */
    public function countUsers(array $cohorts): array
    {
        if (!$cohorts) {
            return [];
        }
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(u.cohort) AS cohort', 'COUNT(u.id) AS users')
            ->from(User::class, 'u')
            ->andWhere('u.cohort IN (:cohorts)')
            ->setParameter('cohorts', $cohorts)
            ->groupBy('u.cohort')
            ->getQuery()
            ->getArrayResult();

        return array_combine(array_map(intval(...), array_column($rows, 'cohort')), array_map(intval(...), array_column($rows, 'users')));
    }
}
