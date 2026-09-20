<?php

namespace App\Repository;

use App\Entity\Avis;
use App\Entity\Biere;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Avis>
 */
class AvisRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Avis::class);
    }

    /** @return list<Avis> */
    public function recents(int $max = 3): array
    {
        return $this->createQueryBuilder('a')
            ->orderBy('a.publieLe', 'DESC')
            ->setMaxResults($max)
            ->getQuery()->getResult();
    }

    /** @return list<Avis> */
    public function pourBiere(Biere $biere): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.biere = :b')->setParameter('b', $biere)
            ->orderBy('a.publieLe', 'DESC')
            ->getQuery()->getResult();
    }
}
