<?php

namespace App\Tests\Controller;

use App\Tests\DatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Le catalogue des parcours (/parcours) : la page qu'on trouve en cherchant « apprendre Symfony en ligne ». */
final class TrackCatalogueTest extends WebTestCase
{
    use DatabaseTrait;

    public function testLeCatalogueListeLesParcoursPubliesEtSIndexe(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/parcours');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorCount(1, 'h1');
        $this->assertSelectorTextContains('.track-card h2 a[href="/parcours/decouverte"]', 'Découverte', 'Les fiches de l\'accueil, titres sous le h1.');
        $this->assertSelectorExists('.track-card .track-chapters li', 'Chapitres et notions, comme à l\'accueil.');
        $this->assertFalse($client->getResponse()->headers->has('X-Robots-Tag'), 'Le catalogue s\'indexe.');
        $this->assertSame('http://localhost/parcours', $crawler->filter('link[rel="canonical"]')->attr('href'));
        $this->assertStringContainsString('Symfony', (string) $crawler->filter('title')->text(), 'Le titre nomme le framework des parcours publiés.');
        $this->assertSelectorExists('header nav a[href="/parcours"]', 'Le menu y mène.');

        $lists = [];
        foreach ($crawler->filter('script[type="application/ld+json"]') as $script) {
            $data = json_decode($script->textContent, true, flags: \JSON_THROW_ON_ERROR);
            $lists[$data['@type']] = $data;
        }
        $this->assertSame(1, $lists['ItemList']['numberOfItems']);
        $this->assertSame(['@type' => 'ListItem', 'position' => 1], array_intersect_key($lists['ItemList']['itemListElement'][0], ['@type' => 1, 'position' => 1]));
        $this->assertSame(['Course', 'Découverte', 'http://localhost/parcours/decouverte'], [
            $lists['ItemList']['itemListElement'][0]['item']['@type'],
            $lists['ItemList']['itemListElement'][0]['item']['name'],
            $lists['ItemList']['itemListElement'][0]['item']['url'],
        ]);
        $this->assertArrayHasKey('BreadcrumbList', $lists);
    }

    public function testFiltrerParFrameworkSeSuitSansSIndexer(): void
    {
        $client = static::createClient();
        $client->request('GET', '/parcours?framework=symfony');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('X-Robots-Tag', 'noindex, follow');
        $this->assertSelectorExists('.track-card h2 a[href="/parcours/decouverte"]');
    }

    public function testUnFrameworkInconnuMontreToutPlutotQuUnePageVide(): void
    {
        $client = static::createClient();
        $client->request('GET', '/parcours?framework=cobol');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.track-card h2 a[href="/parcours/decouverte"]');
    }
}
