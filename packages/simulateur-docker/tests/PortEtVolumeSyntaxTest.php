<?php

declare(strict_types=1);

namespace Forelse\DockerSim\Tests;

use Forelse\DockerSim\Engine\DockerException;
use Forelse\DockerSim\Engine\PortSyntax;
use Forelse\DockerSim\Engine\VolumeSyntax;

/** Ports publiés et montages : la même forme courte pour `docker run` et Compose, donc les mêmes règles. */
final class PortEtVolumeSyntaxTest extends SimulatorTestCase
{
    public function testUnePlageDePortsPublieChaquePort(): void
    {
        $this->assertSame([
            ['host' => 8000, 'container' => 80, 'protocol' => 'tcp', 'ip' => '0.0.0.0'],
            ['host' => 8001, 'container' => 81, 'protocol' => 'tcp', 'ip' => '0.0.0.0'],
        ], PortSyntax::parse('8000-8001:80-81'));
        $this->assertSame([['host' => null, 'container' => 80, 'protocol' => 'udp', 'ip' => '127.0.0.1']], PortSyntax::parse('127.0.0.1::80/udp'));
        $this->assertSame([['host' => 8080, 'container' => 80, 'protocol' => 'tcp', 'ip' => '0.0.0.0']], PortSyntax::parse('8080-8090:80'), 'Une plage de l\'hôte pour un port : le premier libre.');
    }

    public function testUnPortInvalideEstRefuse(): void
    {
        foreach (['8080:abc' => 'invalid containerPort: abc', 'x:80' => 'invalid hostPort: x', '8080:0' => 'invalid containerPort: 0', '80:80-81' => 'invalid ranges specified'] as $spec => $message) {
            try {
                PortSyntax::parse($spec);
                $this->fail($spec);
            } catch (DockerException $e) {
                $this->assertStringContainsString($message, $e->getMessage(), $spec);
            }
        }
    }

    public function testUnMontageSuitLesMemesReglesPartout(): void
    {
        $hote = static fn (string $source) => '/projet/'.ltrim($source, './');
        $this->assertSame(['type' => 'bind', 'source' => '/projet/data', 'target' => '/var/data', 'readOnly' => true], VolumeSyntax::parse('./data:/var/data:ro', $hote));
        $this->assertSame(['type' => 'volume', 'source' => 'base', 'target' => '/var/lib/postgresql', 'readOnly' => false], VolumeSyntax::parse('base:/var/lib/postgresql', $hote));

        foreach (['./data::/x' => 'empty section between colons', './data:relatif' => 'mount path must be absolute', 'base:relatif' => 'mount path must be absolute', 'anonyme' => 'mount path must be absolute'] as $spec => $message) {
            try {
                VolumeSyntax::parse($spec, $hote);
                $this->fail($spec);
            } catch (DockerException $e) {
                $this->assertStringContainsString($message, $e->getMessage(), $spec);
            }
        }
    }

    public function testLaCliEtComposeSontDAccord(): void
    {
        $this->files(['compose.yaml' => "services:\n  web:\n    image: nginx:alpine\n    ports: [\"8000-8001:80-81\"]\n"]);
        $this->cliOk('compose up -d');
        $this->assertSame([8000, 8001], array_column($this->docker()->store->findContainer(basename($this->project).'-web-1')->ports, 'host'), 'Compose publie toute la plage.');

        $this->cliOk('run -d --name cli -p 9000-9001:80-81 nginx:alpine');
        $this->assertSame([9000, 9001], array_column($this->docker()->store->findContainer('cli')->ports, 'host'), 'La CLI aussi.');

        [$code, $output] = $this->cli('run -d -v ./data:relatif nginx:alpine');
        $this->assertSame(125, $code);
        $this->assertStringContainsString('Error response from daemon: invalid volume specification', $output);

        $this->files(['compose.yaml' => "services:\n  web:\n    image: nginx:alpine\n    volumes: [\"./data:relatif\"]\n"]);
        [$code, $output] = $this->cli('compose up -d');
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('mount path must be absolute', $output);
    }
}
