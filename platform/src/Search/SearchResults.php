<?php

namespace App\Search;

/** Les résultats d'une recherche, et le nombre de résultats de chaque type (les onglets), filtre compris ou non. */
final readonly class SearchResults
{
    /**
     * @param list<SearchHit>    $hits
     * @param array<string, int> $types type => nombre de résultats, dans l'ordre de SearchDocument::TYPES
     */
    public function __construct(
        public string $query,
        public ?string $type,
        public array $hits,
        public int $total,
        public array $types,
    ) {
    }
}
