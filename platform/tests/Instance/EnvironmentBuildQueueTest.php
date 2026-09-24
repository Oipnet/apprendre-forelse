<?php

namespace App\Tests\Instance;

use App\Instance\EnvironmentBuildQueue;
use App\Instance\InstalledEnvironments;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class EnvironmentBuildQueueTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/file-empaqueteur-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testLesDemandesSortentDansLOrdreOuEllesSontArrivees(): void
    {
        $queue = $this->queue();
        $queue->push('app:environnement:installer', ['https://exemple.test/a.git']);
        usleep(1000);
        $queue->push('app:environnement:synchroniser', []);

        $this->assertSame(['command' => 'app:environnement:installer', 'arguments' => ['https://exemple.test/a.git']], $queue->pop());
        $this->assertSame(['command' => 'app:environnement:synchroniser', 'arguments' => []], $queue->pop());
        $this->assertNull($queue->pop());
    }

    public function testUneDemandeNeLanceQueLesCommandesDEnvironnement(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->queue()->push('app:admin', ['pirate@example.test']);
    }

    /** Le volume est partagé : une demande déposée par quelqu'un d'autre que la plateforme est jetée si elle sort du cadre. */
    public function testUneDemandeForgeeEstJetee(): void
    {
        mkdir($this->directory.'/'.EnvironmentBuildQueue::DIRECTORY);
        file_put_contents($this->directory.'/'.EnvironmentBuildQueue::DIRECTORY.'/1.json', json_encode(['command' => 'cache:clear', 'arguments' => []]));
        file_put_contents($this->directory.'/'.EnvironmentBuildQueue::DIRECTORY.'/2.json', json_encode(['command' => 'app:environnement:installer', 'arguments' => [['tableau']]]));
        file_put_contents($this->directory.'/'.EnvironmentBuildQueue::DIRECTORY.'/3.json', 'pas du json');

        $this->assertNull($this->queue()->pop());
        $this->assertSame([], glob($this->directory.'/'.EnvironmentBuildQueue::DIRECTORY.'/*'));
    }

    private function queue(): EnvironmentBuildQueue
    {
        return new EnvironmentBuildQueue(new InstalledEnvironments($this->directory), EnvironmentBuildQueue::BUILDER);
    }
}
