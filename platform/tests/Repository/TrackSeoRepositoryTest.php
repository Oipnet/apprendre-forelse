<?php

namespace App\Tests\Repository;

use App\Entity\TrackSeo;
use App\Repository\TrackSeoRepository;
use App\Tests\DatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Le référencement saisi dans l'admin est lu en une requête par requête HTTP, quel que soit le nombre de lectures. */
final class TrackSeoRepositoryTest extends KernelTestCase
{
    use DatabaseTrait;

    public function testToutLeReferencementEstLuEnUneFois(): void
    {
        self::bootKernel();
        $entityManager = $this->resetDatabase();
        foreach (['symfony' => 'Formation Symfony', 'laravel' => 'Formation Laravel'] as $trackId => $title) {
            $entityManager->persist((new TrackSeo($trackId))->setSeoTitle($title));
        }
        $entityManager->flush();
        $entityManager->clear();
        $overrides = static::getContainer()->get(TrackSeoRepository::class);

        $this->assertSame('Formation Symfony', $overrides->findOneByTrack('symfony')?->getSeoTitle());
        // Table vidée derrière le dos du dépôt : s'il refaisait une requête par lecture, il ne trouverait plus rien.
        $entityManager->createQuery('DELETE FROM '.TrackSeo::class)->execute();
        $this->assertSame('Formation Symfony', $overrides->findOneByTrack('symfony')?->getSeoTitle(), 'Le title puis la description : une seule lecture.');
        $this->assertSame('Formation Laravel', $overrides->findOneByTrack('laravel')?->getSeoTitle());
        $this->assertNull($overrides->findOneByTrack('inconnu'));

        // Entre deux requêtes HTTP (kernel.reset), la table est relue.
        $overrides->reset();
        $this->assertNull($overrides->findOneByTrack('symfony'));
    }
}
