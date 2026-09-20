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

        $this->assertSame('nuxt', $environment->framework->id);
        // Le profil dit comment ce projet se joue : ses tests sont des tests Vitest, pas PHPUnit.
        $this->assertFalse($environment->framework->runsPhpunit());
        $this->assertSame('', $environment->phpVersion);
        $this->assertSame([], $environment->cacheDirs);
        $this->assertSame('envs/nuxt-4.zip', $environment->archivePath());
    }

    /** Les caches par défaut viennent du profil ; « cache: » dans environment.yaml a le dernier mot. */
    public function testLesCachesViennentDuProfilSaufMentionContraire(): void
    {
        $environments = new EnvironmentRegistry(self::ROOT.'/environments');

        $this->assertSame(['var/cache'], $environments->get('symfony-8')->cacheDirs);
        $this->assertSame(['storage/framework/views'], $environments->get('laravel-13')->cacheDirs);
        // environments/docker/environment.yaml déclare « cache: [] ».
        $this->assertSame([], $environments->get('docker')->cacheDirs);
    }

    public function testUnFrameworkInconnuEstSignaleAvecLaListeDeCeuxQuiExistent(): void
    {
        $tmp = sys_get_temp_dir().'/env-'.bin2hex(random_bytes(6));
        mkdir($tmp.'/maison', recursive: true);
        file_put_contents($tmp.'/maison/environment.yaml', "id: maison\nphp: '8.4'\nframework: rails\n");

        try {
            $this->expectException(\App\Content\ContentException::class);
            $this->expectExceptionMessage('framework « rails » inconnu (symfony, laravel, docker, nuxt)');
            (new EnvironmentRegistry($tmp))->get('maison');
        } finally {
            @unlink($tmp.'/maison/environment.yaml');
            @rmdir($tmp.'/maison');
            @rmdir($tmp);
        }
    }
}
