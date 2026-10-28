<?php

namespace App\Share;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Le site, tel qu'on l'écrit sur une image ou dans un texte de partage : « apprendre.forelse.fr ». Tiré de l'adresse
 * publique (DEFAULT_URI, qui vaut APP_URL), jamais de l'en-tête Host de la requête : un Host inventé changerait la
 * version de l'image, donc un rendu et un fichier de plus par valeur, sur une instance qui accepte tous les noms.
 */
final readonly class SiteHost
{
    public function __construct(
        #[Autowire(env: 'DEFAULT_URI')]
        private string $siteUrl,
    ) {
    }

    public function name(): string
    {
        $host = (string) parse_url($this->siteUrl, \PHP_URL_HOST);

        return preg_replace('/^www\./', '', $host) ?? $host;
    }
}
