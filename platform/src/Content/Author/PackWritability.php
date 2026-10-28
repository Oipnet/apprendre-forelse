<?php

namespace App\Content\Author;

use App\Content\ContentException;
use App\Content\ExerciseOwner;

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

    public function writable(ExerciseOwner $owner): bool
    {
        return ($this->isWritable)($owner->directory);
    }

    public function assert(ExerciseOwner $owner): void
    {
        if (!$this->writable($owner)) {
            throw new ContentException(sprintf('%s « %s » n\'est pas modifiable (dossier en lecture seule).', $owner->holdsPractice() ? 'Le pack' : 'Le parcours', $owner->id));
        }
    }
}
