<?php

namespace App\Tests\Entity;

use App\Entity\Cohort;
use PHPUnit\Framework\TestCase;

final class CohortTest extends TestCase
{
    public function testLesAccesCommencentLeJourDonneParLHorlogeEtDurentUnAn(): void
    {
        $cohort = new Cohort(new \DateTimeImmutable('2026-03-02 17:45'));

        $this->assertEquals(new \DateTimeImmutable('2026-03-02 00:00'), $cohort->getAccessStartsAt());
        $this->assertEquals(new \DateTimeImmutable('2027-03-02 00:00'), $cohort->getAccessEndsAt());
    }
}
