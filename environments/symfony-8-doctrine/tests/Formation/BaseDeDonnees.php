<?php

namespace App\Tests\Formation;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * Outil des tests d'exercices : base de test recréée à partir des entités de l'apprenant.
 * Les tests ne dépendent donc ni des migrations ni de l'état de la base de l'aperçu.
 */
trait BaseDeDonnees
{
    protected function recreerLaBase(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine')->getManager();
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->dropDatabase();
        if ($metadata) {
            $schemaTool->createSchema($metadata);
        }

        return $entityManager;
    }

    /**
     * Persiste des objets et vide l'EntityManager (les lectures suivantes relisent la base).
     */
    protected function enregistrer(object ...$objets): void
    {
        $entityManager = static::getContainer()->get('doctrine')->getManager();
        foreach ($objets as $objet) {
            $entityManager->persist($objet);
        }
        $entityManager->flush();
        $entityManager->clear();
    }
}
