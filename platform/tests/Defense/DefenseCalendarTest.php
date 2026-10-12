<?php

namespace App\Tests\Defense;

use App\Content\Chapter;
use App\Content\Track;
use App\Defense\DefenseCalendar;
use App\Entity\Cohort;
use App\Entity\Defense;
use App\Entity\DefenseMode;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** L'invitation d'agenda d'une soutenance : lisible par les agendas (RFC 5545). */
final class DefenseCalendarTest extends KernelTestCase
{
    public function testLInvitationRespecteLeFormatICalendar(): void
    {
        $track = new Track('symfony', 'pack', 'Symfony pour les devs PHP, de la première route à la mise en production', 'Un parcours.', 'symfony-8', [new Chapter('c1', 'Routes', ['e1'])], '/tmp');
        $debut = new \DateTimeImmutable('2026-12-02 14:00', new \DateTimeZone('Europe/Paris'));
        $defense = new Defense(new Cohort(), $track->id, $debut, 45, DefenseMode::OnSite, '12 rue de l\'École; bâtiment B, salle 204', new \DateTimeImmutable('2026-11-01'));

        $ics = static::getContainer()->get(DefenseCalendar::class)->ics($defense, $track);

        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        $this->assertStringContainsString("DTSTART:20261202T130000Z\r\n", $ics, 'En UTC : 14 h à Paris, 13 h UTC en hiver.');
        $this->assertStringContainsString("DTEND:20261202T134500Z\r\n", $ics);
        $this->assertStringContainsString('LOCATION:12 rue de l\'École\; bâtiment B\, salle 204', $ics, 'Point-virgule et virgule échappés.');
        foreach (explode("\r\n", $ics) as $line) {
            $this->assertLessThanOrEqual(75, \strlen($line), sprintf('Ligne de plus de 75 octets : « %s ».', $line));
            $this->assertTrue(mb_check_encoding($line, 'UTF-8'), 'Le pliage ne coupe pas un caractère.');
        }
        $deplie = str_replace("\r\n ", '', $ics);
        $this->assertStringContainsString('SUMMARY:Soutenance · Symfony pour les devs PHP\, de la première route à la mise en production', $deplie);
        $this->assertSame('soutenance-2026-12-02.ics', DefenseCalendar::filename($defense));
    }
}
