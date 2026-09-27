<?php

namespace App\Admin;

use App\Content\ContentRepository;
use App\Content\Track;

/** Les parcours installés, en liste de choix pour l'administration : titre (et état) => identifiant. */
final class TrackChoices
{
    public function __construct(private readonly ContentRepository $content)
    {
    }

    /** @return array<string, string> */
    public function choices(): array
    {
        $choices = [];
        foreach ($this->content->tracks() as $track) {
            $choices[self::label($track)] = $track->id;
        }

        return $choices;
    }

    public static function label(Track $track): string
    {
        return $track->title.($track->isRestricted() ? ' (en préparation)' : '');
    }
}
