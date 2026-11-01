<?php

namespace App\Search;

use App\Content\Article;
use App\Content\Practice;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * « Ça pourrait vous intéresser » : les contenus les plus proches d'une page, d'après Meilisearch. On lui pose comme
 * question les notions et le titre de la page, sans la page elle-même. Seules les pages d'entrée sont proposées
 * (exercices de Pratique, articles, notions, parcours), pas les chapitres ni les exercices d'un parcours.
 *
 * Gardé une heure (search.related.cache) : l'index change au plus toutes les dix minutes, et une page lue souvent ne
 * doit pas interroger Meilisearch à chaque fois. Meilisearch éteint, lent ou injoignable : aucun lien, la page s'affiche.
 */
final readonly class RelatedContent
{
    public const int LIMIT = 4;
    private const array TYPES = [SearchDocument::PRACTICE, SearchDocument::ARTICLE, SearchDocument::CONCEPT, SearchDocument::TRACK];
    /** Une page n'attend pas plus pour ses liens : au-delà, elle s'affiche sans. */
    private const float TIMEOUT = 1.0;

    public function __construct(
        private Meilisearch $meilisearch,
        #[Autowire(service: 'search.related.cache')]
        private CacheInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<string> $exclude identifiants de documents déjà proposés par la page (l'exercice d'un article…)
     *
     * @return list<RelatedLink>
     */
    public function forArticle(Article $article, array $exclude = []): array
    {
        return $this->of(new SearchDocument(SearchDocument::ARTICLE, $article->slug, $article->title, '', keywords: $article->tags), $article->locale, $exclude);
    }

    /** @return list<RelatedLink> */
    public function forPractice(Practice $practice): array
    {
        return $this->of(new SearchDocument(SearchDocument::PRACTICE, $practice->exercise->id, $practice->exercise->title, '', keywords: $practice->exercise->concepts), $practice->locale());
    }

    /**
     * @param list<string> $exclude
     *
     * @return list<RelatedLink>
     */
    public function of(SearchDocument $document, string $locale, array $exclude = []): array
    {
        if (!$this->meilisearch->isEnabled()) {
            return [];
        }
        $exclude = array_values(array_unique([$document->id(), ...$exclude]));
        // Les notions d'abord : Meilisearch ne lit que les dix premiers mots, et elles disent mieux le sujet que le titre.
        $query = implode(' ', [...$document->keywords, $document->title]);

        try {
            return $this->cache->get('related.'.hash('xxh128', $locale."\0".$query."\0".implode(',', $exclude)), function (ItemInterface $item) use ($query, $locale, $exclude): array {
                $item->expiresAfter(3600);

                return $this->search($query, $locale, $exclude);
            });
        } catch (SearchUnavailable $exception) {
            $this->logger->warning($exception->getMessage().' (contenus liés)');

            return [];
        }
    }

    /**
     * @param list<string> $exclude
     *
     * @return list<RelatedLink>
     */
    private function search(string $query, string $locale, array $exclude): array
    {
        [$result] = $this->meilisearch->multiSearch([[
            'indexUid' => $this->meilisearch->indexName($locale),
            'q' => $query,
            'locales' => [SearchIndexer::LANGUAGES[$locale] ?? 'eng'],
            // Une longue question : les derniers mots tombent d'abord, jusqu'à trouver ; la première notion reste exigée.
            // (« frequency » garderait un mot absent de l'index, et ne trouverait rien.)
            'matchingStrategy' => 'last',
            'filter' => [
                sprintf('id NOT IN [%s]', implode(', ', array_map(static fn (string $id) => '"'.$id.'"', $exclude))),
                sprintf('type IN [%s]', implode(', ', self::TYPES)),
            ],
            'limit' => self::LIMIT,
            'attributesToRetrieve' => ['type', 'title', 'url', 'label'],
        ]], self::TIMEOUT) + [[]];

        return array_map(
            static fn (array $hit) => new RelatedLink((string) ($hit['type'] ?? ''), (string) ($hit['title'] ?? ''), (string) ($hit['url'] ?? ''), (string) ($hit['label'] ?? '')),
            $result['hits'] ?? [],
        );
    }
}
