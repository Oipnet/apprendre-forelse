<?php

namespace App\Repository;

use App\Entity\Biere;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Biere>
 */
class BiereRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Biere::class);
    }

    /**
     * La « recherche rapide » du prestataire.
     *
     * FAILLE (chapitre 2, F2.1 et F2.2) : la requête est construite par
     * concaténation de chaînes, en SQL brut. Le terme et le tri arrivent
     * directement de la query string.
     *
     * Déclencher :
     *   /bieres/recherche?q=' OR 1=1 --            → révèle les bières inactives
     *   /bieres/recherche?q=' UNION SELECT id, email, nom, '', 0 FROM client --
     *   /bieres/recherche?tri=(SELECT CASE WHEN ...) → injection dans ORDER BY
     *
     * Corriger : requête paramétrée (:q) + liste fermée de colonnes pour le tri.
     *
     * @return list<array{id:int, slug:string, nom:string, style:string, prix:string}>
     */
    public function rechercher(string $q, string $tri = 'nom'): array
    {
        $sql = "SELECT id, slug, nom, style, prix FROM biere
                WHERE actif = 1 AND (nom LIKE '%$q%' OR description LIKE '%$q%')
                ORDER BY $tri";

        /** @var list<array{id:int, slug:string, nom:string, style:string, prix:string}> $lignes */
        $lignes = $this->getEntityManager()->getConnection()->executeQuery($sql)->fetchAllAssociative();

        return $lignes;
    }

    /**
     * Le filtre par style du catalogue.
     *
     * FAILLE (chapitre 2, F2.4 — la vraie faille du Boss) : QueryBuilder mais
     * andWhere concaténé.  /bieres?style=' OR '1'='1 ignore le filtre.
     * Corriger : ->andWhere('b.style = :style')->setParameter('style', $style).
     *
     * @return list<Biere>
     */
    public function parStyle(string $style): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere("b.style = '$style'")
            ->andWhere('b.actif = true')
            ->orderBy('b.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<Biere> */
    public function catalogue(): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.actif = true')
            ->orderBy('b.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
