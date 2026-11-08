<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * L'atelier COBOL : deux pages servies telles quelles, le programme vit dans le navigateur (playground/src/cobol) et
 * voyage après le # quand on le partage. Le serveur n'a ni API ni stockage pour lui.
 */
final class CobolTest extends WebTestCase
{
    public function testLAtelierGraphiqueSOuvreSansCompteEtSIndexe(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/atelier-cobol');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Atelier COBOL');
        $this->assertSame('/atelier-cobol/terminal', $crawler->filter('a.lien-terminal')->attr('href'));
        $this->assertSelectorExists('#partager', 'Le bouton de partage.');
        $this->assertSelectorExists('#bandeau-partage[hidden]', 'Le bandeau d\'arrivée par un lien partagé, caché tant qu\'aucun programme n\'est reçu.');
        $this->assertSame('http://localhost/atelier-cobol', $crawler->filter('link[rel="canonical"]')->attr('href'), 'Le programme partagé (#…) ne change pas l\'adresse canonique.');
        $this->assertFalse($client->getResponse()->headers->has('X-Robots-Tag'), 'La page s\'indexe.');
    }

    public function testLeTerminalSOuvreEtRenvoieALAtelier(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/atelier-cobol/terminal');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'terminal');
        $this->assertSelectorExists('#tube');
        $this->assertSelectorExists('header a[href="/atelier-cobol"]');
        $this->assertStringContainsString('PARTAGE', $crawler->filter('.aide')->text(), 'La commande de partage est annoncée.');
        $this->assertFalse($client->getResponse()->headers->has('X-Robots-Tag'));
    }

    public function testLesDeuxPagesSontDansLeSitemap(): void
    {
        $client = static::createClient();
        $client->request('GET', '/sitemap.xml');

        $this->assertStringContainsString('<loc>http://localhost/atelier-cobol</loc>', (string) $client->getResponse()->getContent());
        $this->assertStringContainsString('<loc>http://localhost/atelier-cobol/terminal</loc>', (string) $client->getResponse()->getContent());
    }
}
