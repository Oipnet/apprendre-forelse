<?php

namespace App\Content\Framework\Profiles;

/**
 * Ce que partagent les profils exécutés par PHP compilé en WebAssembly (Symfony, Laravel, Docker) : les
 * consignes de rédaction propres à ce runtime, que chacun place en tête de ses conventions. Les consignes
 * génériques de l'atelier (ExerciseDrafter) ne supposent plus PHP : un framework joué autrement (Nuxt)
 * ne les reçoit pas.
 */
final class PhpWasm
{
    public const string DRAFTING = <<<'TEXTE'
        Exécution : PHP 8.4 compilé en WebAssembly, dans le navigateur de l'apprenant.
        - php-wasm n'a pas l'extension intl : évite ce qui en dépend (MoneyType, NumberType sans
          html5, dates localisées).
        - Les liens `docs` peuvent aussi pointer vers le manuel PHP (https://www.php.net/manual/fr/).

        TEXTE;
}
