<?php

namespace App\Content;

/**
 * Une adresse de dépôt telle qu'on peut l'afficher.
 *
 * Le README conseille de mettre un jeton dans l'adresse pour un dépôt privé — c'est la façon la plus
 * simple de cloner sans clé SSH sur le serveur. Il faut donc la garder telle quelle pour cloner, et ne
 * jamais la montrer telle quelle : une page d'administration se copie-colle, se capture, s'imprime.
 */
final readonly class RepositoryUrl
{
    /** `https://jeton@github.com/o/d.git` devient `https://•••@github.com/o/d.git`. */
    public static function withoutCredentials(string $url): string
    {
        $parts = parse_url($url);
        if (false === $parts || !isset($parts['user'], $parts['scheme'])) {
            return $url;
        }
        // Remplacé à sa place exacte, et pas partout : un jeton peut ressembler au reste de l'adresse,
        // et un remplacement global masquerait aussi le nom du dépôt — on ne saurait plus lequel c'est.
        $prefixe = $parts['scheme'].'://';
        $identifiants = $parts['user'].(isset($parts['pass']) ? ':'.$parts['pass'] : '').'@';
        if (!str_starts_with($url, $prefixe.$identifiants)) {
            return $url;
        }

        return $prefixe.'•••@'.substr($url, \strlen($prefixe.$identifiants));
    }
}
