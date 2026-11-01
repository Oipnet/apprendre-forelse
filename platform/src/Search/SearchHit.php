<?php

namespace App\Search;

/**
 * Un résultat, prêt à afficher. Le titre et l'extrait sont du HTML sûr : le texte de Meilisearch est échappé, puis
 * seuls les mots trouvés sont entourés de <mark>.
 */
final readonly class SearchHit
{
    public function __construct(
        public string $type,
        public string $url,
        public string $titleHtml,
        public string $excerptHtml,
        public string $label,
    ) {
    }
}
