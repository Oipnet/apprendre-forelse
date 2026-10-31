<?php

namespace Tests;

use Symfony\Component\DomCrawler\Crawler;

/** A page of the gazette as a browser would receive it: its HTTP status code and its HTML. */
final readonly class Page
{
    public function __construct(
        public string $url,
        public int $statut,
        public string $html,
    ) {
    }

    /** The elements matching the CSS selector (“h1”, “ul.articles li”…). */
    public function filtrer(string $selecteur): Crawler
    {
        return (new Crawler($this->html))->filter($selecteur);
    }

    /** The text of the first element matching the selector, “” if there is none. */
    public function texte(string $selecteur): string
    {
        $elements = $this->filtrer($selecteur);

        return $elements->count() > 0 ? trim($elements->first()->text()) : '';
    }
}
