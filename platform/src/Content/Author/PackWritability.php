<?php

namespace App\Content\Author;

use App\Content\ContentException;
use App\Content\Pack;
use App\Content\Track;

/**
 * Un pack monté en lecture seule (la production) ne s'écrit pas : la règle, commune à tout l'atelier.
 */
final class PackWritability
{
    public static function writable(Track|Pack $owner): bool
    {
        return is_writable($owner->directory);
    }

    public static function assert(Track|Pack $owner): void
    {
        if (!self::writable($owner)) {
            throw new ContentException(sprintf('%s « %s » n\'est pas modifiable (dossier en lecture seule).', $owner instanceof Pack ? 'Le pack' : 'Le parcours', $owner->id));
        }
    }
}
