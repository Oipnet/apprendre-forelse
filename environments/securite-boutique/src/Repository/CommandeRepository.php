<?php

namespace App\Repository;

use App\Entity\Commande;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Commande>
 */
class CommandeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Commande::class);
    }

    /**
     * Le filtre des commandes de l'admin.
     *
     * FAILLE (chapitre 2, F2.3) : DQL construit par concaténation.
     *   /admin/commandes?reference=' OR '1'='1   → ignore le filtre
     * On ne peut pas sortir vers une autre table (le parseur DQL de Doctrine
     * ne connaît que les entités). Corriger : setParameter().
     *
     * @param list<string> $statuts
     * @return list<Commande>
     */
    public function filtrer(?string $reference, array $statuts): array
    {
        $dql = "SELECT c FROM App\Entity\Commande c WHERE c.reference LIKE '%$reference%'";
        if ($statuts) {
            $dql .= ' AND c.statut IN ('.implode(',', array_map(fn ($s) => "'$s'", $statuts)).')';
        }
        $dql .= ' ORDER BY c.creeLe DESC';

        return $this->getEntityManager()->createQuery($dql)->getResult();
    }

    /** @return list<Commande> */
    public function toutes(): array
    {
        return $this->createQueryBuilder('c')->orderBy('c.creeLe', 'DESC')->getQuery()->getResult();
    }

    /** @return list<Commande> */
    public function pourClient(int $clientId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.client = :id')->setParameter('id', $clientId)
            ->orderBy('c.creeLe', 'DESC')
            ->getQuery()->getResult();
    }
}
