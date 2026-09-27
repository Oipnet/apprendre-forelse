<?php

namespace App\Security;

/** Où rediriger sans ouvrir de redirection vers un autre site (« open redirect »). */
final class SafeRedirect
{
    /**
     * Le chemin s'il reste sur ce site, sinon null. « //hote » ou « /\hote » mèneraient ailleurs. Ni blanc ni caractère
     * de contrôle : le navigateur retire une tabulation, et « /<tab>/hote » redeviendrait « //hote ».
     */
    public static function localPath(?string $target): ?string
    {
        return null !== $target && 1 === preg_match('#^/(?![/\\\\])[^\s\x00-\x1f\x7f]*$#', $target) ? $target : null;
    }

    /** L'adresse (un referer, par exemple) si elle est sur l'origine donnée (« https://hote »), sinon null. */
    public static function sameOrigin(?string $url, string $origin): ?string
    {
        return null !== $url && str_starts_with($url, $origin.'/') ? $url : null;
    }
}
