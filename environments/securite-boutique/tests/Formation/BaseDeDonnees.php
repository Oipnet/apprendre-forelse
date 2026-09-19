<?php

namespace App\Tests\Formation;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * Recrée le schéma de la base (SQLite) avant chaque test. Fourni par l'environnement,
 * en lecture seule pour les exercices.
 */
trait BaseDeDonnees
{
    protected function recreerLaBase(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $outil = new SchemaTool($em);
        $classes = $em->getMetadataFactory()->getAllMetadata();
        $outil->dropSchema($classes);
        $outil->createSchema($classes);
    }
}
