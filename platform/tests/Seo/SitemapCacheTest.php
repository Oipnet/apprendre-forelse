<?php

namespace App\Tests\Seo;

use App\Seo\Sitemap;
use App\Tests\DatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Le sitemap en cache appartient à l'adresse du site : une commande lancée sur localhost ne le donne pas au site public. */
final class SitemapCacheTest extends KernelTestCase
{
    use DatabaseTrait;

    public function testEachSiteAddressHasItsOwnSitemap(): void
    {
        self::bootKernel();
        $this->resetDatabase();
        $container = static::getContainer();
        $context = $container->get(UrlGeneratorInterface::class)->getContext();
        $sitemap = $container->get(Sitemap::class);

        $context->setHost('localhost');
        $this->assertStringStartsWith('http://localhost/', $sitemap->entries()[0]['loc']);

        $context->setHost('apprendre.example');
        $context->setScheme('https');
        $this->assertStringStartsWith('https://apprendre.example/', $sitemap->entries()[0]['loc']);
    }
}
