<?php

namespace App\Twig;

use App\Content\Track;
use App\Content\TrackVisibility;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `{% set menu = header_tracks() %}` : les parcours que l'en-tête peut annoncer, ceux que l'utilisateur courant voit
 * (tracks) et ceux en préparation (upcoming, « bientôt », sans lien), chacun dans la langue de la page s'il y est
 * traduit. Un thème s'en sert pour un menu des parcours, sans recopier la liste dans ses gabarits.
 */
final class NavigationExtension extends AbstractExtension
{
    public function __construct(private readonly TrackVisibility $visibility)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('header_tracks', $this->headerTracks(...))];
    }

    /** @return array{tracks: list<Track>, upcoming: list<Track>} */
    public function headerTracks(): array
    {
        return [
            'tracks' => array_values($this->visibility->tracksHere()),
            'upcoming' => array_map($this->visibility->localize(...), $this->visibility->upcoming()),
        ];
    }
}
