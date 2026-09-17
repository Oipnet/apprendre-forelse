<?php

namespace App\Tests\Content;

use App\Content\EnvironmentRegistry;
use PHPUnit\Framework\TestCase;

final class EnvironmentRegistryTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../..';

    public function testLEnvironnementNuxtSePasseDePhp(): void
    {
        $environment = (new EnvironmentRegistry(self::ROOT.'/environments'))->get('nuxt-4');

        $this->assertSame('nuxt', $environment->framework);
        $this->assertSame('', $environment->phpVersion);
        $this->assertSame([], $environment->cacheDirs);
        $this->assertSame('envs/nuxt-4.zip', $environment->archivePath());
    }
}
