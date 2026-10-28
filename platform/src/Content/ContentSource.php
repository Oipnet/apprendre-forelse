<?php

namespace App\Content;

/**
 * D'où ContentRepository tire le contenu : les packs sur le disque (PackLoader), ou un contenu monté en mémoire par un
 * test, sans écrire de pack.
 */
interface ContentSource
{
    public function load(): LoadedContent;

    /** Oublie ce qui a été chargé et mis en cache : à appeler après avoir écrit dans un pack (voir l'atelier). */
    public function forget(): void;
}
