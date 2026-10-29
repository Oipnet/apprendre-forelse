<?php

namespace Tests;

use Symfony\Component\DomCrawler\Crawler;

/** Une page de la gazette telle qu'un navigateur la recevrait : son code HTTP et son HTML. */
final readonly class Page
{
    public function __construct(
        public string $url,
        public int $statut,
        public string $html,
    ) {
    }

    /** Les éléments qui répondent au sélecteur CSS (« h1 », « ul.articles li »…). */
    public function filtrer(string $selecteur): Crawler
    {
        return (new Crawler($this->html))->filter($selecteur);
    }

    /** Le texte du premier élément qui répond au sélecteur, « » s'il n'y en a aucun. */
    public function texte(string $selecteur): string
    {
        $elements = $this->filtrer($selecteur);

        return $elements->count() > 0 ? trim($elements->first()->text()) : '';
    }
}
