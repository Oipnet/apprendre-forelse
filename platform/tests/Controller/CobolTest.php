<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * L'atelier COBOL : trois pages (atelier, terminal, mémo), en français et en anglais, servies telles quelles ; le programme vit dans le navigateur (playground/src/cobol) et
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

    public function testLeMemoSeLitSansScriptEtRenvoieAuxDeuxEcrans(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/atelier-cobol/memo');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Mémo');
        $this->assertSelectorExists('section#cycle');
        $this->assertSelectorExists('section#ruptures');
        $this->assertSelectorExists('.liens-memo a[href="/atelier-cobol"]');
        $this->assertSelectorExists('.liens-memo a[href="/atelier-cobol/terminal"]');
        $this->assertFalse($client->getResponse()->headers->has('X-Robots-Tag'));
        $this->assertSame('http://localhost/atelier-cobol/memo', $crawler->filter('link[rel="canonical"]')->attr('href'));
    }

    public function testLesPagesSontDansLeSitemap(): void
    {
        $client = static::createClient();
        $client->request('GET', '/sitemap.xml');

        $this->assertStringContainsString('<loc>http://localhost/atelier-cobol</loc>', (string) $client->getResponse()->getContent());
        $this->assertStringContainsString('<loc>http://localhost/atelier-cobol/terminal</loc>', (string) $client->getResponse()->getContent());
        $this->assertStringContainsString('<loc>http://localhost/atelier-cobol/memo</loc>', (string) $client->getResponse()->getContent());
        $this->assertStringContainsString('<loc>http://localhost/en/cobol-workshop</loc>', (string) $client->getResponse()->getContent());
        $this->assertStringContainsString('<loc>http://localhost/en/cobol-workshop/memo</loc>', (string) $client->getResponse()->getContent());
    }

    /** La version anglaise : la page porte sa langue (le script la suit), ses liens restent en anglais, chaque version nomme l'autre. */
    public function testLesTroisPagesExistentEnAnglais(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/en/cobol-workshop');

        $this->assertResponseIsSuccessful();
        $this->assertSame('en', $crawler->filter('html')->attr('lang'));
        $this->assertSelectorTextContains('h1', 'COBOL workshop');
        $this->assertSame('/en/cobol-workshop/terminal', $crawler->filter('a.lien-terminal')->attr('href'));
        $this->assertSame('/en/contact', $crawler->filter('#defi')->attr('data-contact'));
        $this->assertSame('/en/cobol-workshop/memo', $crawler->filter('#defi')->attr('data-memo'));
        $this->assertSame('http://localhost/atelier-cobol', $crawler->filter('link[rel="alternate"][hreflang="fr"]')->attr('href'));

        $crawler = $client->request('GET', '/en/cobol-workshop/terminal');
        $this->assertResponseIsSuccessful();
        $this->assertSame('en', $crawler->filter('html')->attr('lang'));
        $this->assertSelectorExists('header a[href="/en/cobol-workshop"]');
        $this->assertStringContainsString('SHARE', $crawler->filter('.aide')->text());

        $crawler = $client->request('GET', '/en/cobol-workshop/memo');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'COBOL workshop memo');
        $this->assertSelectorExists('section#ruptures');
        $this->assertSelectorExists('.liens-memo a[href="/en/cobol-workshop/terminal"]');
        $this->assertSame('http://localhost/atelier-cobol/memo', $crawler->filter('link[rel="alternate"][hreflang="fr"]')->attr('href'));

        $crawler = $client->request('GET', '/atelier-cobol');
        $this->assertSame('fr', $crawler->filter('html')->attr('lang'));
        $this->assertSame('http://localhost/en/cobol-workshop', $crawler->filter('link[rel="alternate"][hreflang="en"]')->attr('href'));
    }
}
