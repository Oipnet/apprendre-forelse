<?php

namespace App\Content\Author;

use App\Content\ContentException;
use App\Content\Pack;
use App\Content\Track;

/**
 * Un pack monté en lecture seule (la production) ne s'écrit pas : la règle, commune à tout l'atelier.
 *
 * La question est posée au système de fichiers (is_writable) ; un test la pose à une doublure, car sous root un
 * dossier en 0555 reste inscriptible et le cas « lecture seule » ne se reproduirait pas.
 */
final readonly class PackWritability
{
    /** @var \Closure(string): bool */
    private \Closure $isWritable;

    /** @param (\Closure(string): bool)|null $isWritable le dossier accepte-t-il l'écriture ? (is_writable par défaut) */
    public function __construct(?\Closure $isWritable = null)
    {
        $this->isWritable = $isWritable ?? is_writable(...);
    }

    public function writable(Track|Pack $owner): bool
    {
        return ($this->isWritable)($owner->directory);
    }

    public function assert(Track|Pack $owner): void
    {
        if (!$this->writable($owner)) {
            throw new ContentException(sprintf('%s « %s » n\'est pas modifiable (dossier en lecture seule).', $owner instanceof Pack ? 'Le pack' : 'Le parcours', $owner->id));
        }
    }
}
