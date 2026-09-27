<?php

namespace App\Tests\Entity;

use App\Entity\TrackPricing;
use App\Entity\TrackSeo;
use App\Tests\DatabaseTrait;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/** La date de modification d'un tarif ou d'un titre SEO vient de l'horloge, quel que soit le chemin d'écriture. */
final class TouchListenerTest extends KernelTestCase
{
    use DatabaseTrait;

    public function testUneModificationEstDateeParLHorloge(): void
    {
        self::bootKernel();
        $clock = new MockClock('2026-03-02 10:15:00');
        static::getContainer()->set(ClockInterface::class, $clock);
        $manager = $this->resetDatabase();

        $pricing = (new TrackPricing('decouverte'))->setNormalPrice(4900);
        $seo = (new TrackSeo('decouverte'))->setSeoTitle('Découverte');
        $manager->persist($pricing);
        $manager->persist($seo);
        $manager->flush();
        $this->assertEquals($clock->now(), $pricing->getUpdatedAt(), 'Création : la date de l\'horloge.');
        $this->assertEquals($clock->now(), $seo->getUpdatedAt());

        $clock->modify('+1 day');
        $pricing->setNormalPrice(5900);
        $manager->flush();
        $this->assertEquals($clock->now(), $pricing->getUpdatedAt(), 'Modification : la nouvelle date.');
        $this->assertEquals(new \DateTimeImmutable('2026-03-02 10:15:00'), $seo->getUpdatedAt(), 'Rien changé, rien daté.');

        $manager->clear();
        $this->assertEquals($clock->now(), $manager->find(TrackPricing::class, $pricing->getId())?->getUpdatedAt(), 'Et c\'est bien ce qui est enregistré.');
    }
}
