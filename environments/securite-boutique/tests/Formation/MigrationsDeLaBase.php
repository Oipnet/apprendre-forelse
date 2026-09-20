<?php

namespace App\Tests\Formation;

use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Outil des tests d'exercices : construire la base avec les migrations, comme en production,
 * puis la comparer aux entités.
 */
trait MigrationsDeLaBase
{
    /** Tables que les entités ne décrivent pas : la table des versions, la file de Messenger. */
    private static array $horsDesEntites = ['doctrine_migration_versions', 'messenger_messages'];

    /** Supprime toutes les tables : on repart d'une base vide, comme un serveur neuf. */
    protected function viderLaBase(): void
    {
        $entityManager = static::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($entityManager))->dropDatabase();
        $entityManager->clear();
    }

    /**
     * Joue les migrations jusqu'à une version (la dernière par défaut) et renvoie la sortie.
     *
     * @param string $version « latest », « first », « prev », ou le nom complet d'une migration
     */
    protected function migrer(string $version = 'latest'): string
    {
        // Un noyau neuf à chaque fois, comme deux déploiements successifs : Doctrine refuse de
        // rejouer (up puis down) une même instance de migration dans un processus.
        $noyau = static::createKernel();
        $noyau->boot();
        try {
            $tester = new CommandTester((new Application($noyau))->find('doctrine:migrations:migrate'));
            $code = $tester->execute(['version' => $version, '--allow-no-migration' => true], ['interactive' => false]);
        } finally {
            $noyau->shutdown();
        }
        static::assertSame(0, $code, "Les migrations ont échoué :\n".$tester->getDisplay());
        static::getContainer()->get('doctrine')->getManager()->clear();

        return $tester->getDisplay();
    }

    /**
     * Le SQL qu'il faudrait encore exécuter pour que la base corresponde aux entités.
     * Vide : les migrations sont en phase avec le code.
     *
     * @return list<string>
     */
    protected function ecartsAvecLesEntites(): array
    {
        $entityManager = static::getContainer()->get('doctrine')->getManager();
        $sql = (new SchemaTool($entityManager))->getUpdateSchemaSql($entityManager->getMetadataFactory()->getAllMetadata());

        return array_values(array_filter($sql, static function (string $instruction): bool {
            foreach (self::$horsDesEntites as $table) {
                if (str_contains($instruction, $table)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /** @return list<string> les classes de migration trouvées dans migrations/, dans l'ordre */
    protected function migrationsDisponibles(): array
    {
        $fichiers = glob(static::getContainer()->getParameter('kernel.project_dir').'/migrations/*.php') ?: [];
        sort($fichiers);

        return array_map(static fn (string $fichier) => 'DoctrineMigrations\\'.basename($fichier, '.php'), $fichiers);
    }
}
