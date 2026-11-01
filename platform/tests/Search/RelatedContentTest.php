<?php

namespace App\Tests\Search;

use App\Search\RelatedContent;
use App\Search\RelatedLink;
use App\Search\SearchDocument;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/** Les contenus proches d'une page : la question posée à Meilisearch, le cache, et rien quand il ne répond pas. */
final class RelatedContentTest extends KernelTestCase
{
    private FakeMeilisearch $meilisearch;

    protected function setUp(): void
    {
        $this->meilisearch = new FakeMeilisearch();
        $this->meilisearch->responses['POST /multi-search'] = [200, ['results' => [['hits' => [
            ['type' => 'notion', 'title' => 'Contrôleur', 'url' => '/notions/controleur', 'label' => ''],
            ['type' => 'article', 'title' => 'Valider une API', 'url' => '/blog/valider', 'label' => 'API'],
        ]]]]];
    }

    private function related(): RelatedContent
    {
        return new RelatedContent($this->meilisearch->client(), new ArrayAdapter(), new NullLogger());
    }

    private static function document(): SearchDocument
    {
        return new SearchDocument(SearchDocument::PRACTICE, 'exemple', 'Lire un en-tête', '/pratique/exemple', keywords: ['Contrôleur', 'HttpKernel']);
    }

    public function testLesNotionsEtLeTitreFontLaQuestionSansLaPageElleMeme(): void
    {
        $related = $this->related();

        $links = $related->of(self::document(), 'fr', ['article-deja-propose']);

        $this->assertEquals([
            new RelatedLink('notion', 'Contrôleur', '/notions/controleur', ''),
            new RelatedLink('article', 'Valider une API', '/blog/valider', 'API'),
        ], $links);
        $query = $this->meilisearch->requests[0]['body']['queries'][0];
        $this->assertSame('site_fr', $query['indexUid']);
        $this->assertSame('Contrôleur HttpKernel Lire un en-tête', $query['q']);
        $this->assertSame(['id NOT IN ["pratique-exemple", "article-deja-propose"]', 'type IN [pratique, article, notion, parcours]'], $query['filter']);
        $this->assertSame(RelatedContent::LIMIT, $query['limit']);

        // La même page, servie de nouveau : Meilisearch n'est pas réinterrogé.
        $related->of(self::document(), 'fr', ['article-deja-propose']);
        $this->assertCount(1, $this->meilisearch->requests);
    }

    public function testMeilisearchInjoignableAucunLienEtRienNEstGarde(): void
    {
        $related = $this->related();
        $this->meilisearch->down = true;

        $this->assertSame([], $related->of(self::document(), 'fr'));

        // Revenu : la page suivante a ses liens, l'échec n'a pas été mis en cache.
        $this->meilisearch->down = false;
        $this->assertCount(2, $related->of(self::document(), 'fr'));
    }

    public function testSansMeilisearchRienNEstDemande(): void
    {
        $this->assertSame([], static::getContainer()->get(RelatedContent::class)->of(self::document(), 'fr'));
    }
}
