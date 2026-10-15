<?php

namespace App\Tests\Controller;

use App\Tests\DatabaseTrait;
use App\Tests\PacksTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Les notions en anglais (/en/concepts) : faites du seul contenu traduit (parcours entièrement traduits, exercices
 * de Pratique traduits), sous les libellés anglais. Pack de démo : « Controller » est abordé par le premier exercice
 * du parcours traduit et par l'exercice de Pratique traduit ; c'est la traduction de « Contrôleur ».
 */
final class ConceptEnglishTest extends WebTestCase
{
    use DatabaseTrait;
    use PacksTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    protected function tearDown(): void
    {
        $this->restorePacks();
        parent::tearDown();
    }

    public function testLIndexAnglaisNeListeQueLesNotionsDuContenuTraduit(): void
    {
        $crawler = $this->client->request('GET', '/en/concepts');

        $this->assertResponseIsSuccessful();
        $this->assertSame('en', $crawler->filter('html')->attr('lang'));
        $this->assertSame('Concepts covered in the exercises', $crawler->filter('h1')->text());
        $this->assertSame(['/en/concepts/controller'], $crawler->filter('.concepts-list a')->each(static fn (Crawler $a) => $a->attr('href')));
        $this->assertStringContainsString('2 exercises', $crawler->filter('.concepts-list a')->text());
        $this->assertFalse($this->client->getResponse()->headers->has('X-Robots-Tag'), 'L\'index anglais s\'indexe.');
        $this->assertSame('http://localhost/en/concepts', $crawler->filter('link[rel="canonical"]')->attr('href'));
        $this->assertSame(['en' => 'http://localhost/en/concepts', 'fr' => 'http://localhost/notions', 'x-default' => 'http://localhost/notions'], $this->alternates($crawler));
        $this->assertSame('/en/concepts', $crawler->filter('.site-footer a:contains("Concepts")')->attr('href'), 'Le pied de page anglais mène aux notions anglaises.');

        $this->assertSame(['en' => 'http://localhost/en/concepts', 'fr' => 'http://localhost/notions', 'x-default' => 'http://localhost/notions'], $this->alternates($this->client->request('GET', '/notions')), 'L\'index français nomme l\'anglais.');
    }

    /** La page anglaise mène aux exercices anglais, et s'annonce avec la page française de la même notion. */
    public function testUneNotionAnglaiseMeneAuxExercicesAnglais(): void
    {
        $crawler = $this->client->request('GET', '/en/concepts/controller');

        $this->assertResponseIsSuccessful();
        $this->assertSame('Controller', $crawler->filter('h1')->text());
        $links = $crawler->filter('.concept-exercises a')->each(static fn (Crawler $a) => $a->attr('href'));
        $this->assertSame([
            '/en/tracks/decouverte/01-bonjour',
            '/en/tracks/decouverte',
            '/en/tracks/decouverte/chapter/bonjour/summary',
            '/en/practice/exemple-map-request-header',
        ], $links);
        $this->assertSelectorTextContains('main', 'Read a header with #[MapRequestHeader]');
        $this->assertStringContainsString('2 exercises work on this concept', $crawler->filter('.lead')->text());
        $this->assertSame(['/en/concepts'], $crawler->filter('.breadcrumb a[href^="/en/concepts"]')->each(static fn (Crawler $a) => $a->attr('href')));
        $this->assertSame('http://localhost/en/concepts/controller', $crawler->filter('link[rel="canonical"]')->attr('href'));
        $this->assertStringStartsWith('Controller: 2 exercises to practise it', $crawler->filter('title')->text());
        // « Controller » traduit « Contrôleur » à la même place dans les deux exercices : les deux pages se nomment.
        $this->assertSame(['en' => 'http://localhost/en/concepts/controller', 'fr' => 'http://localhost/notions/controleur', 'x-default' => 'http://localhost/notions/controleur'], $this->alternates($crawler));
        $this->assertSame(['en' => 'http://localhost/en/concepts/controller', 'fr' => 'http://localhost/notions/controleur', 'x-default' => 'http://localhost/notions/controleur'], $this->alternates($this->client->request('GET', '/notions/controleur')), 'La page française nomme l\'anglaise.');
    }

    /** Sans intro anglaise, la page n'est qu'une liste de liens, comme en français : elle ne s'indexe pas. */
    public function testUneNotionAnglaiseSansIntroNeSIndexePas(): void
    {
        $crawler = $this->client->request('GET', '/en/concepts/controller');

        $this->assertResponseHeaderSame('X-Robots-Tag', 'noindex, follow');
        $this->assertCount(0, $crawler->filter('.concept-intro'));

        $this->client->request('GET', '/sitemap.xml');
        $sitemap = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('<loc>http://localhost/en/concepts</loc>', $sitemap);
        $this->assertStringNotContainsString('<loc>http://localhost/en/concepts/controller</loc>', $sitemap);
    }

    /** L'intro anglaise s'écrit dans `notions/en/`, nommée comme l'adresse anglaise de la notion (controller.md). */
    public function testUneIntroAnglaiseOuvreLaPageEtLIndexe(): void
    {
        $this->usePacks(__DIR__.'/../../../examples/packs', __DIR__.'/../Fixtures/packs/pratique');
        $this->client = $this->restartClient();

        $crawler = $this->client->request('GET', '/en/concepts/controller');

        $this->assertResponseIsSuccessful();
        $this->assertFalse($this->client->getResponse()->headers->has('X-Robots-Tag'));
        $this->assertStringContainsString('<code>Request</code>', $crawler->filter('.concept-intro')->html());
        $this->assertSame('Symfony controller: from request to response', $crawler->filter('h1')->text());
        $this->assertStringStartsWith('A controller receives the Request', (string) $crawler->filter('meta[name="description"]')->attr('content'));

        // La page française garde son intro française.
        $this->assertSame('Contrôleur Symfony : de la requête à la réponse', $this->client->request('GET', '/notions/controleur')->filter('h1')->text());

        $this->client->request('GET', '/sitemap.xml');
        $this->assertStringContainsString('<loc>http://localhost/en/concepts/controller</loc>', (string) $this->client->getResponse()->getContent());
    }

    public function testUneNotionInconnueOuFrancaiseEstIntrouvableEnAnglais(): void
    {
        $this->client->request('GET', '/en/concepts/controleur');
        $this->assertResponseStatusCodeSame(404);

        // « Route parameter » n'est vu que sur un exercice : pas de page, comme en français.
        $this->client->request('GET', '/en/concepts/route-parameter');
        $this->assertResponseStatusCodeSame(404);
    }

    /** Les pastilles d'un exercice anglais mènent aux notions anglaises ; celles d'un exercice français, aux françaises. */
    public function testLesPastillesDUnExerciceAnglaisMenentAuxNotionsAnglaises(): void
    {
        $crawler = $this->client->request('GET', '/en/practice/exemple-map-request-header');
        $this->assertSame(['/en/concepts/controller'], $crawler->filter('.exercise-concepts a')->each(static fn (Crawler $a) => $a->attr('href')));

        $config = json_decode((string) $crawler->filter('[data-playground]')->attr('data-config'), true);
        $this->client->request('GET', $config['exerciseUrl']);
        $exercise = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame(['Controller' => '/en/concepts/controller'], $exercise['conceptUrls']);

        $crawler = $this->client->request('GET', '/pratique/exemple-map-request-header');
        $this->assertSame(['/notions/controleur'], $crawler->filter('.exercise-concepts a')->each(static fn (Crawler $a) => $a->attr('href')));
    }

    private function restartClient(): KernelBrowser
    {
        self::ensureKernelShutdown();

        return static::createClient();
    }

    /** @return array<string, string> hreflang => adresse */
    private function alternates(Crawler $crawler): array
    {
        $alternates = [];
        foreach ($crawler->filter('link[rel="alternate"][hreflang]') as $link) {
            $alternates[$link->getAttribute('hreflang')] = $link->getAttribute('href');
        }
        ksort($alternates);

        return $alternates;
    }
}
