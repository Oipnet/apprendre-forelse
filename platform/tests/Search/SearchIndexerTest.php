<?php

namespace App\Tests\Search;

use App\Search\IndexSearch;
use App\Search\SearchCatalog;
use App\Search\SearchIndexer;
use App\Search\SearchUnavailable;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/** L'envoi du contenu à Meilisearch : tout la première fois, rien sans changement, les pages disparues retirées. */
final class SearchIndexerTest extends KernelTestCase
{
    private FakeMeilisearch $meilisearch;
    private ArrayAdapter $cache;

    protected function setUp(): void
    {
        $this->meilisearch = new FakeMeilisearch();
        $this->cache = new ArrayAdapter();
    }

    private function indexer(): SearchIndexer
    {
        return new SearchIndexer($this->meilisearch->client(), static::getContainer()->get(SearchCatalog::class), $this->cache, new NullLogger());
    }

    public function testLePremierPassageEnvoieReglagesEtDocumentsDansChaqueLangue(): void
    {
        // Index pas encore créé : Meilisearch répond 404 à la liste des documents.
        $this->meilisearch->responses['GET /indexes/site_fr/documents'] = [404, ['code' => 'index_not_found']];
        $this->meilisearch->responses['GET /indexes/site_en/documents'] = [404, ['code' => 'index_not_found']];

        $report = $this->indexer()->index();

        $this->assertSame(['site_fr', 'site_en'], array_keys($report));
        $this->assertGreaterThan(0, $report['site_fr']);
        $this->assertSame([
            'GET /indexes/site_fr/documents', 'PATCH /indexes/site_fr/settings', 'POST /indexes/site_fr/documents',
            'GET /indexes/site_en/documents', 'PATCH /indexes/site_en/settings', 'POST /indexes/site_en/documents',
        ], $this->meilisearch->calls(), 'Rien à retirer d\'un index neuf.');

        $settings = $this->meilisearch->requests[1]['body'];
        $this->assertSame(['title', 'keywords', 'label', 'summary', 'text'], $settings['searchableAttributes']);
        $this->assertSame([['attributePatterns' => ['*'], 'locales' => ['fra']]], $settings['localizedAttributes']);
        $this->assertSame([['attributePatterns' => ['*'], 'locales' => ['eng']]], $this->meilisearch->requests[4]['body']['localizedAttributes']);

        $this->assertSame('/indexes/site_fr/documents?primaryKey=id', $this->meilisearch->requests[2]['path']);
        $documents = $this->meilisearch->requests[2]['body'];
        $this->assertCount($report['site_fr'], $documents);
        $this->assertContains('pratique-exemple-map-request-header', array_column($documents, 'id'));
    }

    public function testSansChangementRienNEstReecritEtUnePageDisparueEstRetiree(): void
    {
        $this->meilisearch->responses['GET /indexes/site_fr/documents'] = [404, []];
        $this->meilisearch->responses['GET /indexes/site_en/documents'] = [404, []];
        $this->indexer()->index();
        $sent = [
            'fr' => array_column($this->meilisearch->requests[2]['body'], 'id'),
            'en' => array_column($this->meilisearch->requests[5]['body'], 'id'),
        ];

        // Meilisearch a tout indexé : un nouveau passage ne réécrit rien.
        foreach ($sent as $locale => $ids) {
            $this->meilisearch->responses['GET /indexes/site_'.$locale.'/documents'] = [200, ['results' => array_map(static fn (string $id) => ['id' => $id], $ids), 'total' => \count($ids)]];
        }
        $this->meilisearch->requests = [];
        $this->assertSame(['site_fr' => null, 'site_en' => null], $this->indexer()->index());
        $this->assertSame(['GET /indexes/site_fr/documents', 'GET /indexes/site_en/documents'], $this->meilisearch->calls());

        // Une page retirée du site (article dépublié…) reste dans l'index : elle en sort au passage suivant.
        $ids = [...$sent['fr'], 'article-retire'];
        $this->meilisearch->responses['GET /indexes/site_fr/documents'] = [200, ['results' => array_map(static fn (string $id) => ['id' => $id], $ids), 'total' => \count($ids)]];
        $this->meilisearch->requests = [];
        $this->indexer()->index();
        $this->assertSame(['GET /indexes/site_fr/documents', 'PATCH /indexes/site_fr/settings', 'POST /indexes/site_fr/documents', 'POST /indexes/site_fr/documents/delete-batch', 'GET /indexes/site_en/documents'], $this->meilisearch->calls());
        $this->assertSame(['article-retire'], $this->meilisearch->requests[3]['body']);
    }

    public function testUnIndexPerduEstReecritMemeSansChangementDuContenu(): void
    {
        $this->meilisearch->responses['GET /indexes/site_fr/documents'] = [404, []];
        $this->meilisearch->responses['GET /indexes/site_en/documents'] = [404, []];
        $this->indexer()->index();
        $this->meilisearch->requests = [];

        // Volume de Meilisearch recréé vide : l'empreinte n'a pas changé, mais l'index est vide.
        $this->indexer()->index();

        $this->assertContains('POST /indexes/site_fr/documents', $this->meilisearch->calls());
    }

    public function testMeilisearchInjoignableNArretePasLeWorker(): void
    {
        $this->meilisearch->down = true;

        $this->indexer()->onSchedule(new IndexSearch());
        $this->expectException(SearchUnavailable::class);
        $this->indexer()->index();
    }
}
