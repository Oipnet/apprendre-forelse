<?php

namespace App\Repository;

use App\Entity\TentativeConnexion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TentativeConnexion>
 */
class TentativeConnexionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TentativeConnexion::class);
    }
}
