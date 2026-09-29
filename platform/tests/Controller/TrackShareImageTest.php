<?php

namespace App\Tests\Controller;

use App\Tests\DatabaseTrait;
use App\Tests\PacksTrait;
use App\Tests\TrackImageTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

/** L'image de partage d'un parcours (clé « image » de track.yaml) : servie, et annoncée sur les pages du parcours. */
final class TrackShareImageTest extends WebTestCase
{
    use DatabaseTrait;
    use PacksTrait;
    use TrackImageTrait;

    private string $tmp;

    protected function setUp(): void
    {
        // Le pack de démo, avec une image de partage pour « decouverte ».
        $this->tmp = sys_get_temp_dir().'/partage-'.bin2hex(random_bytes(4));
        $filesystem = new Filesystem();
        $filesystem->mirror(__DIR__.'/../../../examples/packs/demo', $this->tmp.'/demo');
        $trackYaml = $this->tmp.'/demo/tracks/decouverte/track.yaml';
        $filesystem->dumpFile($trackYaml, "image: partage.png\n".file_get_contents($trackYaml));
        $filesystem->dumpFile($this->tmp.'/demo/tracks/decouverte/partage.png', self::pngHeader(1200, 630));
        $this->usePacks($this->tmp);
    }

    protected function tearDown(): void
    {
        $this->restorePacks();
        (new Filesystem())->remove($this->tmp);
        parent::tearDown();
    }

    public function testLesPagesDuParcoursPartagentSonImage(): void
    {
        $client = static::createClient();
        $expected = 'http://localhost/parcours/decouverte/image-de-partage?v='.filemtime($this->tmp.'/demo/tracks/decouverte/partage.png');

        foreach (['/parcours/decouverte', '/parcours/decouverte/chapitre/bonjour/sommaire', '/parcours/decouverte/01-bonjour'] as $page) {
            $crawler = $client->request('GET', $page);
            $this->assertResponseIsSuccessful($page);
            $this->assertSame($expected, $crawler->filter('meta[property="og:image"]')->attr('content'), $page);
            $this->assertStringStartsWith('Découverte · ', (string) $crawler->filter('meta[property="og:image:alt"]')->attr('content'), $page);
            $this->assertSame('summary_large_image', $crawler->filter('meta[name="twitter:card"]')->attr('content'), $page);
        }

        $client->request('GET', $expected);
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'image/png');
        $this->assertStringContainsString('immutable', (string) $client->getResponse()->headers->get('Cache-Control'));
    }

    public function testSansImageLaRouteRepond404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/parcours/inconnu/image-de-partage');
        $this->assertResponseStatusCodeSame(404);
    }
}
