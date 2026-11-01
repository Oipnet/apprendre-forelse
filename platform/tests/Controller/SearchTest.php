<?php

namespace App\Tests\Controller;

use App\Search\Meilisearch;
use App\Tests\DatabaseTrait;
use App\Tests\PacksTrait;
use App\Tests\Search\FakeMeilisearch;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/** La page de recherche et le champ de l'en-tête, Meilisearch simulé. */
final class SearchTest extends WebTestCase
{
    use DatabaseTrait;
    use PacksTrait;

    private FakeMeilisearch $meilisearch;

    private function client(bool $enabled = true): KernelBrowser
    {
        $client = static::createClient();
        // Le même noyau d'une requête à l'autre : le faux Meilisearch reste en place.
        $client->disableReboot();
        $this->resetDatabase();
        $this->meilisearch = new FakeMeilisearch();
        if ($enabled) {
            static::getContainer()->set(Meilisearch::class, $this->meilisearch->client());
        }

        return $client;
    }

    /** @param list<array<string, mixed>> $hits */
    private function answer(array $hits, array $types): void
    {
        $this->meilisearch->responses['POST /multi-search'] = [200, ['results' => [
            ['hits' => $hits, 'estimatedTotalHits' => \count($hits)],
            ['hits' => [], 'facetDistribution' => ['type' => $types]],
        ]]];
    }

    public function testSansMeilisearchNiPageNiChamp(): void
    {
        $client = $this->client(enabled: false);

        $client->request('GET', '/recherche?q=symfony');
        $this->assertResponseStatusCodeSame(404);

        $crawler = $client->request('GET', '/pratique');
        $this->assertCount(0, $crawler->filter('form[role="search"][action="/recherche"]'));
    }

    public function testLEnTeteProposeLaRechercheEtLaPageVideInvite(): void
    {
        $client = $this->client();

        $crawler = $client->request('GET', '/recherche');

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('header form.site-search[action="/recherche"] input[name="q"]'), 'Le champ de l\'en-tête.');
        $this->assertCount(1, $crawler->filter('main form[role="search"] input[name="q"][autofocus]'));
        $this->assertSame([], $this->meilisearch->requests, 'Sans mots, pas de recherche.');
        $this->assertStringContainsString('noindex', (string) $client->getResponse()->headers->get('X-Robots-Tag'), 'Une page de résultats ne s\'indexe pas.');
    }

    public function testLesResultatsMettentLesMotsTrouvesEnValeurSansLaisserPasserDeHtml(): void
    {
        $client = $this->client();
        $this->answer([
            ['type' => 'pratique', 'url' => '/pratique/exemple-map-request-header', 'label' => 'Symfony 8.1', '_formatted' => [
                'title' => "Lire un en-tête avec #[\u{E000}MapRequestHeader\u{E001}]",
                'summary' => 'Un en-tête HTTP arrive directement en argument du contrôleur.',
                'text' => '',
            ]],
            ['type' => 'article', 'url' => '/blog/un-article', 'label' => '', '_formatted' => [
                'title' => 'Un <script>alert(1)</script> article',
                'summary' => 'Sans le mot.',
                'text' => "…le <b>code</b> avec \u{E000}MapRequestHeader\u{E001} dedans…",
            ]],
        ], ['article' => 1, 'pratique' => 1]);

        $crawler = $client->request('GET', '/recherche?q=MapRequestHeader');

        $this->assertResponseIsSuccessful();
        $results = $crawler->filter('.search-result');
        $this->assertCount(2, $results);
        $this->assertSame('MapRequestHeader', $results->eq(0)->filter('h2 a mark')->text());
        $this->assertSame('/pratique/exemple-map-request-header', $results->eq(0)->filter('h2 a')->attr('href'));
        $this->assertSame('Pratique · Symfony 8.1', $results->eq(0)->filter('p')->first()->text());
        $this->assertSame('MapRequestHeader', $results->eq(0)->filter('h2 a')->attr('data-umami-event-q'));

        $this->assertCount(0, $crawler->filter('.search-result script, .search-result b'), 'Le texte de Meilisearch est échappé.');
        $this->assertSame('Un <script>alert(1)</script> article', $results->eq(1)->filter('h2 a')->text());
        $this->assertSame('…le <b>code</b> avec MapRequestHeader dedans…', $results->eq(1)->filter('.search-excerpt')->text(), 'Le mot n\'est que dans le texte : l\'extrait montre où.');

        // Onglets dans l'ordre des types, avec leurs totaux ; la recherche dans la langue de la page.
        $this->assertSame(['Tout 2', 'Pratique 1', 'Articles 1'], $crawler->filter('.search-tabs a')->each(static fn (Crawler $a) => $a->text()));
        $queries = $this->meilisearch->requests[0]['body']['queries'];
        $this->assertSame('site_fr', $queries[0]['indexUid']);
        $this->assertSame('MapRequestHeader', $queries[0]['q']);
        $this->assertNull($queries[0]['filter']);
    }

    public function testUnOngletFiltreParTypeEtLaPageAnglaiseChercheDansLIndexAnglais(): void
    {
        $client = $this->client();
        $this->answer([], ['notion' => 2]);

        $crawler = $client->request('GET', '/en/search?q=controller&type=notion');

        $this->assertResponseIsSuccessful();
        $queries = $this->meilisearch->requests[0]['body']['queries'];
        $this->assertSame('site_en', $queries[0]['indexUid']);
        $this->assertSame('type = "notion"', $queries[0]['filter']);
        $this->assertStringContainsString('Nothing matches “controller”', $crawler->filter('.search-empty')->text());

        // Un type inconnu est ignoré, pas injecté dans le filtre.
        $client->request('GET', '/recherche?q=x&type=notion"%20OR%20type%20%3D%20"x');
        $this->assertNull($this->meilisearch->requests[1]['body']['queries'][0]['filter']);
    }

    public function testMeilisearchInjoignableLaPageLeDit(): void
    {
        $client = $this->client();
        $this->meilisearch->down = true;

        $crawler = $client->request('GET', '/recherche?q=symfony');

        $this->assertResponseStatusCodeSame(503);
        $this->assertCount(1, $crawler->filter('.search-unavailable'));
    }

    public function testRobotsTxtEcarteLesPagesDeResultats(): void
    {
        $client = $this->client();

        $client->request('GET', '/robots.txt');

        $this->assertStringContainsString("Disallow: /recherche\n", (string) $client->getResponse()->getContent());
        $this->assertStringContainsString("Disallow: /en/search\n", (string) $client->getResponse()->getContent());
    }

    protected function tearDown(): void
    {
        $this->restorePacks();
        parent::tearDown();
    }

    /** @param list<array{type: string, title: string, url: string, label: string}> $hits */
    private function related(array $hits): void
    {
        $this->meilisearch->responses['POST /multi-search'] = [200, ['results' => [['hits' => $hits]]]];
    }

    public function testUnArticleProposeLesContenusProchesSousLeTitreEtDansLaColonne(): void
    {
        $this->usePacks(__DIR__.'/../../../examples/packs', __DIR__.'/../Fixtures/packs/pratique');
        $client = $this->client();
        $this->related([
            ['type' => 'pratique', 'title' => 'Lire un en-tête', 'url' => '/pratique/exemple-map-request-header', 'label' => 'Symfony 8.1'],
            ['type' => 'notion', 'title' => 'Contrôleur', 'url' => '/notions/controleur', 'label' => ''],
            ['type' => 'article', 'title' => 'Les tests en direct', 'url' => '/blog/les-tests-en-direct', 'label' => ''],
            ['type' => 'parcours', 'title' => 'Découverte', 'url' => '/parcours/decouverte', 'label' => ''],
        ]);

        $crawler = $client->request('GET', '/blog/symfony-dans-le-navigateur');

        $this->assertResponseIsSuccessful();
        // Sur un téléphone, sous le titre : on le voit sans finir l'article. Les trois premiers.
        $this->assertSame(['/pratique/exemple-map-request-header', '/notions/controleur', '/blog/les-tests-en-direct'], $crawler->filter('article header .related-compact a')->each(static fn (Crawler $a) => $a->attr('href')));
        // Sur un écran large, la colonne qui reste en vue : les quatre, avec leur type.
        $sidebar = $crawler->filter('.related-sidebar');
        $this->assertCount(4, $sidebar->filter('a'));
        $this->assertSame('Pratique · Symfony 8.1', $sidebar->filter('li span')->first()->text());
        $this->assertSame('article', $sidebar->filter('a')->first()->attr('data-umami-event-depuis'));

        $query = $this->meilisearch->requests[0]['body']['queries'][0];
        $this->assertStringContainsString('"article-symfony-dans-le-navigateur"', $query['filter'][0], 'L\'article ne se propose pas lui-même.');
    }

    public function testSansMeilisearchLArticleGardeSaMiseEnPage(): void
    {
        $this->usePacks(__DIR__.'/../../../examples/packs', __DIR__.'/../Fixtures/packs/pratique');
        $client = $this->client(enabled: false);

        $crawler = $client->request('GET', '/blog/symfony-dans-le-navigateur');

        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('.related'));
        $this->assertStringContainsString('max-w-3xl', (string) $crawler->filter('main')->attr('class'));
    }

    public function testLaPagePubliqueDUnExerciceDePratiqueProposeSesContenusProches(): void
    {
        $client = $this->client();
        $this->related([['type' => 'notion', 'title' => 'Contrôleur', 'url' => '/notions/controleur', 'label' => '']]);

        $crawler = $client->request('GET', '/pratique/exemple-map-request-header');

        $this->assertResponseIsSuccessful();
        $this->assertSame('/notions/controleur', $crawler->filter('.related a')->attr('href'), 'Dans la page, avant le chargement du playground : les moteurs de recherche suivent le lien.');
        $client->request('GET', '/api/exercises/pratique/exemple-map-request-header');
        $exercise = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame([['type' => 'notion', 'title' => 'Contrôleur', 'url' => '/notions/controleur']], $exercise['practice']['related'], 'Et dans le volet Consignes du playground.');
    }
}
