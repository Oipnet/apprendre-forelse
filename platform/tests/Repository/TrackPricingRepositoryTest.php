<?php

namespace App\Tests\Repository;

use App\Entity\TrackPricing;
use App\Repository\TrackPricingRepository;
use App\Tests\DatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Les tarifs sont lus en une requête par requête HTTP, quel que soit le nombre de parcours demandés. */
final class TrackPricingRepositoryTest extends KernelTestCase
{
    use DatabaseTrait;

    public function testTousLesTarifsSontLusEnUneFois(): void
    {
        self::bootKernel();
        $entityManager = $this->resetDatabase();
        foreach (['symfony' => 7900, 'laravel' => 5900] as $trackId => $price) {
            $entityManager->persist((new TrackPricing($trackId))->setNormalPrice($price));
        }
        $entityManager->flush();
        $entityManager->clear();
        $pricings = static::getContainer()->get(TrackPricingRepository::class);

        $this->assertSame(7900, $pricings->findOneByTrack('symfony')?->getNormalPrice());
        // Table vidée derrière le dos du dépôt : s'il refaisait une requête par parcours, il ne trouverait plus rien.
        $entityManager->createQuery('DELETE FROM '.TrackPricing::class)->execute();
        $this->assertSame(5900, $pricings->findOneByTrack('laravel')?->getNormalPrice());
        $this->assertNull($pricings->findOneByTrack('inconnu'));

        // Entre deux requêtes HTTP (kernel.reset), la table est relue.
        $pricings->reset();
        $this->assertNull($pricings->findOneByTrack('symfony'));
    }
}
