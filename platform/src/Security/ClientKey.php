<?php

namespace App\Security;

/**
 * La clé d'un limiteur de débit par adresse IP. Une machine en IPv6 dispose en général d'un /64 entier : changer
 * d'adresse dans ce bloc ne doit pas remettre son compteur à zéro. IPv4 telle quelle.
 */
final class ClientKey
{
    public static function of(?string $ip): string
    {
        if (null === $ip || '' === $ip) {
            return 'inconnu';
        }
        $binary = filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6) ? @inet_pton($ip) : false;
        if (false === $binary || 16 !== \strlen($binary)) {
            return $ip;
        }
        // IPv4 dans IPv6 (::ffff:1.2.3.4) : c'est une adresse IPv4, gardée entière.
        if (str_starts_with($binary, str_repeat("\0", 10)."\xff\xff")) {
            return (string) inet_ntop(substr($binary, 12));
        }

        return inet_ntop(substr($binary, 0, 8).str_repeat("\0", 8)).'/64';
    }
}
