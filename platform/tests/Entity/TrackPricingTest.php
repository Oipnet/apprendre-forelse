<?php

namespace App\Tests\Entity;

use App\Entity\TrackPricing;
use PHPUnit\Framework\TestCase;

final class TrackPricingTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-09-17 12:00');
    }

    private function pricing(?\DateTimeImmutable $endsAt = null, ?int $quota = null, bool $active = true): TrackPricing
    {
        return (new TrackPricing('symfony'))
            ->setNormalPrice(7900)
            ->setFounderPrice(4900)
            ->setFounderActive($active)
            ->setFounderEndsAt($endsAt)
            ->setFounderQuotaMax($quota);
    }

    public function testLePrixFondateurActifSApplique(): void
    {
        $this->assertSame(4900, $this->pricing()->currentPrice($this->now, 0));
        $this->assertSame(4900, $this->pricing($this->now->modify('+1 day'), 100)->currentPrice($this->now, 99));
    }

    public function testUnPrixFondateurInactifLaissePlaceAuPrixNormal(): void
    {
        $this->assertSame(7900, $this->pricing(active: false)->currentPrice($this->now, 0));
    }

    public function testLePrixFondateurCesseASaDate(): void
    {
        $this->assertSame(7900, $this->pricing($this->now->modify('-1 second'))->currentPrice($this->now, 0));
        $this->assertSame(7900, $this->pricing($this->now)->currentPrice($this->now, 0), 'La date de fin est exclue.');
    }

    public function testLePrixFondateurCesseQuandLeQuotaEstAtteint(): void
    {
        $pricing = $this->pricing(quota: 100);

        $this->assertSame(4900, $pricing->currentPrice($this->now, 99));
        $this->assertSame(1, $pricing->founderSeatsLeft(99));
        $this->assertSame(7900, $pricing->currentPrice($this->now, 100));
        $this->assertSame(0, $pricing->founderSeatsLeft(120));
    }

    public function testUnParcoursAZeroEstGratuit(): void
    {
        $pricing = (new TrackPricing('decouverte'))->setNormalPrice(0);

        $this->assertTrue($pricing->isFree());
        $this->assertSame(0, $pricing->currentPrice($this->now, 0));
        $this->assertFalse($pricing->isFounderPriceApplicable($this->now, 0));
    }
}
