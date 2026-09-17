<?php

namespace App\Tests\Entity;

use App\Entity\TrackAccess;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class TrackAccessTest extends TestCase
{
    public function testActifEntreSesDates(): void
    {
        $now = new \DateTimeImmutable('2026-09-17 12:00');
        $user = new User();

        $this->assertTrue(TrackAccess::gift($user, 't', $now->modify('-1 day'))->isActive($now), 'À vie.');
        $this->assertTrue(TrackAccess::gift($user, 't', $now->modify('-1 day'), $now->modify('+1 day'))->isActive($now));
        $this->assertFalse(TrackAccess::gift($user, 't', $now->modify('-1 year'), $now->modify('-1 day'))->isActive($now), 'Expiré.');
        $this->assertFalse(TrackAccess::gift($user, 't', $now->modify('+1 day'))->isActive($now), 'Pas encore commencé.');
    }

    public function testRevoquerTermineLAccesMaintenant(): void
    {
        $now = new \DateTimeImmutable('2026-09-17 12:00');
        $access = TrackAccess::gift(new User(), 't', $now->modify('-1 month'));

        $access->revoke($now);
        $this->assertFalse($access->isActive($now));
        $this->assertEquals($now, $access->getEndsAt());

        $expired = TrackAccess::gift(new User(), 't', $now->modify('-1 year'), $now->modify('-1 month'));
        $expired->revoke($now);
        $this->assertEquals($now->modify('-1 month'), $expired->getEndsAt(), 'Un accès déjà terminé garde sa date de fin.');
    }
}
