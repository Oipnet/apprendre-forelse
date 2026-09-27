<?php

namespace App\Content;

use Psr\Clock\ClockInterface;

/**
 * Ce qu'un visiteur sans compte peut atteindre : les parcours publiés et la Pratique parue. Un parcours en
 * préparation et un exercice de Pratique programmé n'en font pas partie, quel que soit le compte qui demande.
 *
 * Sert partout où une page s'adresse à tout le monde — sitemap, index des notions — pour qu'une seule
 * définition dise ce qui est public.
 */
final readonly class PublishedContent
{
    public function __construct(
        private ContentRepository $content,
        private ClockInterface $clock,
    ) {
    }

    /** @return list<Track> les parcours publiés, dans l'ordre d'affichage */
    public function tracks(): array
    {
        return array_values(array_filter($this->content->tracks(), static fn (Track $track) => !$track->isRestricted()));
    }

    /** @return list<Practice> */
    public function practices(): array
    {
        return array_values(array_filter($this->content->practices(), fn (Practice $p) => !$p->isRestricted() && !$p->isScheduled($this->clock->now())));
    }
}
