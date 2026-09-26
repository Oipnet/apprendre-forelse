<?php

namespace App\Tests;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/** Les compteurs des limites de débit vivent dans la base : un redémarrage (un déploiement) ne les remet pas à zéro. */
final class RateLimiterPersistenceTest extends KernelTestCase
{
    use DatabaseTrait;

    public function testUnCompteurEntameLEstToujoursApresUnRedemarrage(): void
    {
        self::bootKernel();
        $this->resetDatabase();
        $this->assertSame(2, $this->contact()->create('203.0.113.7')->consume(3)->getRemainingTokens());

        // Comme au démarrage du conteneur : nouveau kernel, cache (dont var/cache/…/pools) vidé.
        $cacheDir = self::$kernel->getCacheDir();
        self::ensureKernelShutdown();
        (new Filesystem())->remove($cacheDir.'/pools');
        self::bootKernel();

        $this->assertSame(1, $this->contact()->create('203.0.113.7')->consume()->getRemainingTokens(), 'Le compteur a survécu.');
        $this->assertSame(4, $this->contact()->create('198.51.100.1')->consume()->getRemainingTokens(), 'Chaque adresse a le sien.');
    }

    /** Lancé à chaque démarrage (docker-entrypoint.sh) : sans lui, la ligne d'une adresse de passage resterait. */
    public function testLesCompteursExpiresSontPurges(): void
    {
        self::bootKernel();
        $this->resetDatabase();
        $this->contact()->create('203.0.113.7')->consume();
        $this->contact()->create('198.51.100.1')->consume();
        $connection = static::getContainer()->get(Connection::class);
        $this->assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM cache_items'));
        // Le premier compteur date d'avant-hier : sa fenêtre d'une heure est passée depuis longtemps.
        $connection->executeStatement(
            'UPDATE cache_items SET item_time = item_time - 172800 WHERE item_id = (SELECT MIN(item_id) FROM cache_items)',
        );

        $tester = new CommandTester((new Application(self::$kernel))->find('cache:pool:prune'));
        $this->assertSame(Command::SUCCESS, $tester->execute([]));

        $this->assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM cache_items'), 'Seul le compteur encore valable reste.');
    }

    /** Formulaire de contact : 5 envois par heure et par adresse IP (config/packages/rate_limiter.yaml). */
    private function contact(): RateLimiterFactoryInterface
    {
        return static::getContainer()->get('limiter.contact');
    }
}
