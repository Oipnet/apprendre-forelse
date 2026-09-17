<?php

namespace App\Tests\Controller;

use App\Seo\PageSeo;
use App\Tests\DatabaseTrait;
use App\Tests\PacksTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Chaque page publique (celles du sitemap) a ses balises : title unique, description, canonical absolu, Open Graph,
 * un seul h1. Packs : démo, Pratique (dont un exercice programmé et un en préparation), cohortes (dont un parcours
 * en préparation).
 */
final class PublicPagesSeoTest extends WebTestCase
{
    use DatabaseTrait;
    use PacksTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $fixtures = __DIR__.'/../Fixtures/packs';
        $this->usePacks(__DIR__.'/../../../examples/packs', $fixtures.'/pratique', $fixtures.'/cohortes');
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    protected function tearDown(): void
    {
        $this->restorePacks();
        parent::tearDown();
    }

    /** @return list<string> */
    private function sitemap(): array
    {
        $this->client->request('GET', '/sitemap.xml');
        $xml = new \SimpleXMLElement((string) $this->client->getResponse()->getContent());

        return array_map(static fn (\SimpleXMLElement $url) => (string) $url->loc, iterator_to_array($xml->url, false));
    }

    public function testChaquePagePubliqueASesBalises(): void
    {
        $titles = [];
        foreach ($this->sitemap() as $url) {
            $crawler = $this->client->request('GET', $url);
            $this->assertResponseIsSuccessful($url);
            $this->assertFalse($this->client->getResponse()->headers->has('X-Robots-Tag'), $url);

            $title = $crawler->filter('title')->text();
            $this->assertNotSame('', $title, $url);
            $this->assertLessThanOrEqual(PageSeo::TITLE_MAX, mb_strlen($title), $url);
            $titles[$title][] = $url;

            $description = (string) $crawler->filter('meta[name="description"]')->attr('content');
            $this->assertGreaterThanOrEqual(50, mb_strlen($description), $url);
            $this->assertLessThanOrEqual(PageSeo::DESCRIPTION_MAX, mb_strlen($description), $url);

            $this->assertSame($url, $crawler->filter('link[rel="canonical"]')->attr('href'), 'Canonical absolu, sans paramètre : '.$url);
            $this->assertSame($url, $crawler->filter('meta[property="og:url"]')->attr('content'), $url);
            $this->assertSame($title, $crawler->filter('meta[property="og:title"]')->attr('content'), $url);
            $this->assertStringStartsWith('http://localhost/', (string) $crawler->filter('meta[property="og:image"]')->attr('content'), $url);
            $this->assertSame('summary_large_image', $crawler->filter('meta[name="twitter:card"]')->attr('content'), $url);
            $this->assertCount(1, $crawler->filter('h1'), 'Un seul h1 : '.$url);
        }

        $this->assertSame([], array_filter($titles, static fn (array $urls) => \count($urls) > 1), 'Chaque title est unique.');
    }

    public function testLeSitemapNeContientNiParcoursNiPratiqueNonPublies(): void
    {
        $urls = $this->sitemap();

        $this->assertContains('http://localhost/parcours/symfony-bases', $urls);
        $this->assertNotContains('http://localhost/parcours/atelier-secret', $urls, 'Parcours en préparation.');
        $this->assertNotContains('http://localhost/pratique/programme', $urls, 'Pratique programmée.');
        $this->assertNotContains('http://localhost/pratique/en-preparation', $urls, 'Pratique en préparation.');
        $this->assertContains('http://localhost/pratique/point-precis', $urls);
    }

    public function testUnParcoursEnPreparationEstAnnonceSansLien(): void
    {
        $this->client->request('GET', '/');

        $this->assertSelectorTextContains('.lp-upcoming', 'Atelier en préparation');
        $this->assertSelectorNotExists('.lp-upcoming a');
        $this->assertSelectorNotExists('a[href="/parcours/atelier-secret"]');
        $this->assertSelectorTextContains('.lp-faq', 'En préparation : Atelier en préparation.');
    }

    public function testLaPratiqueFiltreeAPourCanonicalLaListe(): void
    {
        $crawler = $this->client->request('GET', '/pratique?framework=laravel&nouveautes=1');

        $this->assertSame('http://localhost/pratique', $crawler->filter('link[rel="canonical"]')->attr('href'));
        $this->assertSame('noindex, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
    }

    public function testLesPagesPriveesNOntNiCanonicalNiOpenGraph(): void
    {
        foreach (['/connexion', '/inscription', '/mot-de-passe-oublie'] as $path) {
            $crawler = $this->client->request('GET', $path);
            $this->assertSame('noindex', $crawler->filter('meta[name="robots"]')->attr('content'), $path);
            $this->assertCount(0, $crawler->filter('link[rel="canonical"], meta[property^="og:"]'), $path);
        }
        $this->client->request('GET', '/page-inconnue');
        $this->assertResponseStatusCodeSame(404);
        $this->assertResponseHeaderSame('X-Robots-Tag', 'noindex');
    }

    public function testUnePagePubliqueSansCompteSeRevalideParETag(): void
    {
        $this->client->request('GET', '/parcours/decouverte');
        $etag = $this->client->getResponse()->getEtag();
        $this->assertNotNull($etag);

        $this->client->request('GET', '/parcours/decouverte', server: ['HTTP_IF_NONE_MATCH' => $etag]);
        $this->assertResponseStatusCodeSame(304);

        // Connecté, la page montre le compte : pas de 304 sur la version anonyme.
        $this->client->loginUser($this->createUser());
        $this->client->request('GET', '/parcours/decouverte', server: ['HTTP_IF_NONE_MATCH' => $etag]);
        $this->assertResponseIsSuccessful();
        $this->assertNull($this->client->getResponse()->getEtag());
    }
}
