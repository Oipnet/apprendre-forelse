<?php

namespace App\Search;

/** Un contenu proche d'une page (voir RelatedContent) : de quoi afficher un lien. */
final readonly class RelatedLink
{
    public function __construct(
        public string $type,
        public string $title,
        public string $url,
        public string $label,
    ) {
    }
}
