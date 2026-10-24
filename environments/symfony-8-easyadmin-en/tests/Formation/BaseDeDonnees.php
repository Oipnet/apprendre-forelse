<?php

namespace App\Tests\Formation;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * Exercise test helper: a test database rebuilt from the learner's entities.
 * Tests therefore depend neither on the migrations nor on the state of the preview database.
 */
trait BaseDeDonnees
{
    /** Recreates the database (recreerLaBase: "recreate the database"). */
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
     * Persists objects and clears the EntityManager, so that later reads go back to the database
     * (enregistrer: "save").
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
