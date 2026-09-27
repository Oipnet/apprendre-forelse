<?php

namespace App\Instance;

/** Rapatrie un dépôt Git : ce que l'installateur d'environnements attend de git, et rien d'autre. */
interface GitCheckout
{
    /** Clone superficiel de $url (à la référence $ref, ou la branche par défaut) dans $destination. */
    public function clone(string $url, string $ref, string $destination): void;

    /** Le commit extrait dans $directory. */
    public function commit(string $directory): string;
}
