<?php

namespace App\Twig;

use App\Search\SiteSearch;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `{% if search_enabled() %}` : la recherche du site existe (Meilisearch configuré, voir App\Search). Un thème s'en
 * sert pour n'afficher la loupe de son en-tête que là où la page /recherche répond.
 */
final class SearchExtension extends AbstractExtension
{
    public function __construct(private readonly SiteSearch $search)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('search_enabled', $this->search->isEnabled(...))];
    }
}
