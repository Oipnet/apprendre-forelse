<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Tests;

/** Les « prune » passent par le moteur ; la CLI ne fait qu'afficher ce qu'il a supprimé. */
final class PruneTest extends SimulatorTestCase
{
    public function testLeMoteurSupprimeLesReseauxInutilises(): void
    {
        $docker = $this->docker();
        $docker->createNetwork('front');
        $docker->createNetwork('back');
        $this->cliOk('run -d --name web --network front alpine:3.20 sleep 1000');

        $this->assertSame(['back'], $this->docker()->pruneNetworks(), 'front sert encore à web.');

        $docker = $this->docker();
        $docker->disconnect($docker->findContainer('web') ?? throw new \LogicException(), 'front');
        $this->assertSame(['front'], $this->docker()->pruneNetworks());
        $this->assertSame(['bridge', 'host', 'none'], array_keys($this->docker()->networks()));
    }

    public function testLeMoteurSupprimeLesVolumesAnonymesPuisTous(): void
    {
        $this->cliOk('volume create nomme');
        $this->cliOk('volume create monte');
        $this->cliOk('run --name c -v monte:/data alpine:3.20 true');
        $this->cliOk('run -v /anonyme alpine:3.20 true');
        $this->assertCount(3, $this->docker()->volumes());
        $this->assertSame(['nomme'], $this->docker()->pruneVolumes(true), 'Un volume monté, même par un conteneur arrêté, reste.');
        $this->cliOk('container prune -f');

        $anonymes = $this->docker()->pruneVolumes(false);
        $this->assertCount(1, $anonymes, 'Sans --all, les volumes nommés restent.');
        $this->assertNotContains('monte', $anonymes);
        $this->assertSame(['monte'], $this->docker()->pruneVolumes(true));
        $this->assertSame([], $this->docker()->volumes());
    }

    public function testSystemPruneVideLeCacheSansAvertissementDeNetworkPrune(): void
    {
        $this->files(['Dockerfile' => "FROM alpine:3.20\nRUN echo a > /a\n"]);
        $this->cliOk('build -t app .');
        $this->cliOk('network create inutile');
        $this->assertGreaterThan(0, $this->docker()->buildCacheSize());

        $output = $this->cliOk('system prune -f');

        $this->assertStringStartsWith("WARNING! This will remove:\n  - all stopped containers\n", $output);
        $this->assertStringNotContainsString('custom networks', $output);
        $this->assertSame(0, $this->docker()->buildCacheSize());
        $this->assertArrayNotHasKey('inutile', $this->docker()->networks());
        $this->assertNotNull($this->docker()->findImage('app'), 'Sans --all, une image nommée reste.');
    }
}
