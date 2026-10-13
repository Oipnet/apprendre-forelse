<?php

namespace App\Tests\Command;

use App\Tests\DatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** app:fuseau:convertir : les dates enregistrées en UTC deviennent l'heure qu'il était à Paris, une seule fois. */
final class TimezoneConvertCommandTest extends KernelTestCase
{
    use DatabaseTrait;

    private string $timezone;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetDatabase();
        $this->timezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
        parent::tearDown();
    }

    /** @param array<string, string|bool> $input */
    private function console(array $input): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:fuseau:convertir'));
        $tester->execute($input);

        return $tester;
    }

    private function createdAt(): string
    {
        return (string) static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne('SELECT created_at FROM "user"');
    }

    public function testConvertitLesDatesDeUtcAParisUneSeuleFois(): void
    {
        $this->createUser();
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        // L'été (UTC+2) et l'hiver (UTC+1).
        $connection->executeStatement('UPDATE "user" SET created_at = \'2026-10-13 12:30:00\', email_verified_at = \'2026-12-01 08:00:00\'');

        $tester = $this->console(['from' => 'UTC', 'to' => 'Europe/Paris']);
        $this->assertSame(Command::FAILURE, $tester->getStatusCode(), 'APP_TIMEZONE doit d\'abord valoir le nouveau fuseau.');
        $this->assertStringContainsString('réglez-le d\'abord', $tester->getDisplay(true));

        date_default_timezone_set('Europe/Paris');
        $tester = $this->console(['from' => 'UTC', 'to' => 'Europe/Paris']);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('user.created_at', $tester->getDisplay(true));
        $this->assertSame('2026-10-13 12:30:00', $this->createdAt(), 'Sans --appliquer, rien ne change.');

        $tester = $this->console(['from' => 'UTC', 'to' => 'Europe/Paris', '--appliquer' => true]);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertSame('2026-10-13 14:30:00', $this->createdAt());
        $this->assertSame('2026-12-01 09:00:00', (string) $connection->fetchOne('SELECT email_verified_at FROM "user"'));

        $tester = $this->console(['from' => 'UTC', 'to' => 'Europe/Paris', '--appliquer' => true]);
        $this->assertSame(Command::FAILURE, $tester->getStatusCode(), 'Une seconde conversion décalerait encore les dates.');
        $this->assertSame('2026-10-13 14:30:00', $this->createdAt());
    }
}
