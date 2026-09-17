<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * `{{ data|json_ld }}` dans un <script type="application/ld+json"> : les chevrons et esperluettes sont échappés
 * en <…, pour qu'un texte de pack contenant « </script> » ne puisse pas sortir de la balise.
 */
final class JsonLdExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [new TwigFilter('json_ld', self::encode(...), ['is_safe' => ['html']])];
    }

    /** @param array<string, mixed> $data */
    public static function encode(array $data): string
    {
        return json_encode($data, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_THROW_ON_ERROR);
    }
}
