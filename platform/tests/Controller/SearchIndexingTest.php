<?php

namespace App\Tests\Controller;

use App\Tests\DatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** robots.txt, sitemap.xml et en-têtes X-Robots-Tag : ce qui s'indexe, et ce qui ne s'indexe jamais. */
final class SearchIndexingTest extends WebTestCase
{
    use DatabaseTrait;

    private const array SANDBOX = ['HTTP_HOST' => '127.0.0.1:8001'];

    public function testRobotsTxtBloqueLesEspacesPrivesEtDeclareLeSitemap(): void
    {
        $client = static::createClient();
        $client->request('GET', '/robots.txt');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'text/plain; charset=UTF-8');
        $robots = (string) $client->getResponse()->getContent();
        foreach (['Disallow: /admin', 'Disallow: /cohorte', 'Disallow: /compte', 'Disallow: /*?code=', 'Sitemap: http://localhost/sitemap.xml'] as $line) {
            $this->assertStringContainsString($line, $robots);
        }
        $this->assertStringNotContainsString("Disallow: /\n", $robots);
        // Bloquées, elles ne montreraient pas leur noindex.
        $this->assertStringNotContainsString('/connexion', $robots);
        $this->assertStringNotContainsString('/inscription', $robots);
    }

    public function testLeBacASableInterditTout(): void
    {
        $client = static::createClient();
        $client->request('GET', '/robots.txt', server: self::SANDBOX);

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString("Disallow: /\n", (string) $client->getResponse()->getContent());
        $this->assertStringNotContainsString('Sitemap', (string) $client->getResponse()->getContent());

        $client->request('GET', '/sandbox', server: self::SANDBOX);
        $this->assertResponseHeaderSame('X-Robots-Tag', 'noindex');
    }

    public function testUneInstanceSansIndexationInterditTout(): void
    {
        $initial = $_SERVER['SEARCH_INDEXING'];
        $_SERVER['SEARCH_INDEXING'] = $_ENV['SEARCH_INDEXING'] = '0';
        try {
            $client = static::createClient();
            $client->request('GET', '/robots.txt');
            $this->assertStringContainsString("Disallow: /\n", (string) $client->getResponse()->getContent());

            $client->request('GET', '/');
            $this->assertResponseHeaderSame('X-Robots-Tag', 'noindex');
        } finally {
            $_SERVER['SEARCH_INDEXING'] = $_ENV['SEARCH_INDEXING'] = $initial;
        }
    }

    public function testSitemap(): void
    {
        $client = static::createClient();
        $client->request('GET', '/sitemap.xml');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/xml; charset=UTF-8');
        $xml = new \SimpleXMLElement((string) $client->getResponse()->getContent());
        $lastmods = [];
        foreach ($xml->url as $url) {
            $lastmods[(string) $url->loc] = (string) $url->lastmod;
        }

        $this->assertSame([
            'http://localhost/',
            'http://localhost/parcours/decouverte',
            'http://localhost/parcours/decouverte/01-bonjour',
            'http://localhost/parcours/decouverte/02-bonjour-prenom',
            'http://localhost/pratique',
            'http://localhost/pratique/exemple-map-request-header',
            'http://localhost/ecoles-et-entreprises',
            'http://localhost/contact',
            'http://localhost/mentions-legales',
            'http://localhost/confidentialite',
            'http://localhost/cgv',
        ], array_keys($lastmods));
        foreach ($lastmods as $loc => $lastmod) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $lastmod, $loc);
        }
        $this->assertSame(max($lastmods), $lastmods['http://localhost/'], 'L\'accueil change avec le contenu le plus récent.');

        // Aucune adresse du sitemap n'est marquée noindex.
        foreach (array_keys($lastmods) as $loc) {
            $client->request('GET', $loc);
            $this->assertFalse($client->getResponse()->headers->has('X-Robots-Tag'), $loc);
        }
    }

    public function testLesPagesPriveesSontEnNoindex(): void
    {
        $client = static::createClient();
        foreach (['/connexion', '/inscription', '/mot-de-passe-oublie', '/compte', '/admin', '/cohorte', '/page-inconnue', '/parcours/inconnu'] as $path) {
            $client->request('GET', $path);
            $this->assertResponseHeaderSame('X-Robots-Tag', 'noindex', $path);
        }
    }

    public function testLaPratiqueFiltreeSeSuitSansSIndexer(): void
    {
        $client = static::createClient();
        $client->request('GET', '/pratique?framework=symfony');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('X-Robots-Tag', 'noindex, follow');
    }

    public function testUneAdresseInconnueRepond404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/page-inconnue');

        $this->assertResponseStatusCodeSame(404);
    }
}
