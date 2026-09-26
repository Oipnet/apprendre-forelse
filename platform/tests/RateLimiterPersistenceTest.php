<?php

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
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

    /** Formulaire de contact : 5 envois par heure et par adresse IP (config/packages/rate_limiter.yaml). */
    private function contact(): RateLimiterFactoryInterface
    {
        return static::getContainer()->get('limiter.contact');
    }
}
