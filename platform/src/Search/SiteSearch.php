<?php

namespace App\Search;

/**
 * La recherche du site, telle que la page /recherche la pose à Meilisearch : dans la langue de la page, filtrée par
 * type si le visiteur a choisi un onglet, avec les mots trouvés mis en valeur.
 */
final readonly class SiteSearch
{
    /** Au-delà, Meilisearch ne lit de toute façon que les dix premiers mots. */
    public const int QUERY_MAX_LENGTH = 100;
    public const int LIMIT = 30;

    /** Repères des mots trouvés, hors de tout texte : remplacés par <mark> après l'échappement. */
    private const string MARK_START = "\u{E000}";
    private const string MARK_END = "\u{E001}";

    public function __construct(
        private Meilisearch $meilisearch,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->meilisearch->isEnabled();
    }

    /** @throws SearchUnavailable */
    public function search(string $query, string $locale, ?string $type = null): SearchResults
    {
        $query = mb_substr(trim($query), 0, self::QUERY_MAX_LENGTH);
        $type = \in_array($type, SearchDocument::TYPES, true) ? $type : null;
        $index = $this->meilisearch->indexName($locale);

        [$found, $all] = $this->meilisearch->multiSearch([
            [
                'indexUid' => $index,
                'q' => $query,
                // La langue de l'index : sans elle, Meilisearch devine celle des mots tapés, et se trompe sur deux mots.
                'locales' => [SearchIndexer::LANGUAGES[$locale] ?? 'eng'],
                'limit' => self::LIMIT,
                'filter' => null !== $type ? sprintf('type = "%s"', $type) : null,
                'attributesToRetrieve' => ['type', 'url', 'label'],
                'attributesToHighlight' => ['title', 'summary', 'text'],
                'attributesToCrop' => ['summary:40', 'text:28'],
                'highlightPreTag' => self::MARK_START,
                'highlightPostTag' => self::MARK_END,
                'cropMarker' => '…',
            ],
            // Les totaux de chaque onglet, sans le filtre : un onglet choisi n'efface pas les autres.
            ['indexUid' => $index, 'q' => $query, 'locales' => [SearchIndexer::LANGUAGES[$locale] ?? 'eng'], 'limit' => 0, 'facets' => ['type']],
        ]) + [[], []];

        $counts = $all['facetDistribution']['type'] ?? [];
        $types = [];
        foreach (SearchDocument::TYPES as $known) {
            if (($counts[$known] ?? 0) > 0) {
                $types[$known] = (int) $counts[$known];
            }
        }

        $hits = [];
        foreach ($found['hits'] ?? [] as $hit) {
            $formatted = $hit['_formatted'] ?? [];
            $hits[] = new SearchHit(
                (string) ($hit['type'] ?? ''),
                (string) ($hit['url'] ?? ''),
                self::html((string) ($formatted['title'] ?? '')),
                self::html(self::excerpt((string) ($formatted['summary'] ?? ''), (string) ($formatted['text'] ?? ''))),
                (string) ($hit['label'] ?? ''),
            );
        }

        return new SearchResults($query, $type, $hits, array_sum($types), $types);
    }

    /** Le résumé, sauf si seul le texte contient les mots cherchés : on montre alors où. */
    private static function excerpt(string $summary, string $text): string
    {
        if ('' === $summary || (!str_contains($summary, self::MARK_START) && str_contains($text, self::MARK_START))) {
            return '' !== $text ? $text : $summary;
        }

        return $summary;
    }

    private static function html(string $text): string
    {
        return str_replace([self::MARK_START, self::MARK_END], ['<mark>', '</mark>'], htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'));
    }
}
