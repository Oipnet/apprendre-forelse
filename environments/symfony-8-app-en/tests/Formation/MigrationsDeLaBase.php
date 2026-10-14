<?php

namespace App\Tests\Formation;

use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Exercise test helper: build the database with the migrations, as in production,
 * then compare it with the entities.
 */
trait MigrationsDeLaBase
{
    /** Tables the entities do not describe: the versions table, the Messenger queue. */
    private static array $horsDesEntites = ['doctrine_migration_versions', 'messenger_messages'];

    /** Drops every table (viderLaBase: "empty the database"): back to an empty database, like a new server. */
    protected function viderLaBase(): void
    {
        $entityManager = static::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($entityManager))->dropDatabase();
        $entityManager->clear();
    }

    /**
     * Runs the migrations up to a version (the latest by default) and returns the output (migrer: "migrate").
     *
     * @param string $version "latest", "first", "prev", or the full name of a migration
     */
    protected function migrer(string $version = 'latest'): string
    {
        // A fresh kernel every time, like two successive deployments: Doctrine refuses to
        // run the same migration instance twice (up then down) in one process.
        $noyau = static::createKernel();
        $noyau->boot();
        try {
            $tester = new CommandTester((new Application($noyau))->find('doctrine:migrations:migrate'));
            $code = $tester->execute(['version' => $version, '--allow-no-migration' => true], ['interactive' => false]);
        } finally {
            $noyau->shutdown();
        }
        static::assertSame(0, $code, "The migrations failed:\n".$tester->getDisplay());
        static::getContainer()->get('doctrine')->getManager()->clear();

        return $tester->getDisplay();
    }

    /**
     * The SQL still needed for the database to match the entities (ecartsAvecLesEntites: "gaps with the entities").
     * Empty: the migrations are in step with the code.
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

    /** @return list<string> the migration classes found in migrations/, in order (migrationsDisponibles: "available migrations") */
    protected function migrationsDisponibles(): array
    {
        $fichiers = glob(static::getContainer()->getParameter('kernel.project_dir').'/migrations/*.php') ?: [];
        sort($fichiers);

        return array_map(static fn (string $fichier) => 'DoctrineMigrations\\'.basename($fichier, '.php'), $fichiers);
    }
}
