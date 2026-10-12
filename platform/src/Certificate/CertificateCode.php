<?php

namespace App\Certificate;

/**
 * Le code public d'un certificat : « 7K3M-Q9TD ». Huit caractères tirés au hasard, sans ceux qui se confondent à la
 * lecture ou à la saisie (0/O, 1/I/L, U/V) : il se recopie depuis un PDF ou un profil LinkedIn. 29⁸ combinaisons (500 milliards) : on ne
 * devine pas celui d'un autre.
 */
final class CertificateCode
{
    private const string ALPHABET = '23456789ABCDEFGHJKMNPQRSTWXYZ';

    public static function generate(): string
    {
        $chars = '';
        for ($i = 0; $i < 8; ++$i) {
            $chars .= self::ALPHABET[random_int(0, \strlen(self::ALPHABET) - 1)];
        }

        return substr($chars, 0, 4).'-'.substr($chars, 4);
    }

    /** Le motif d'un code, pour la route : majuscules ou minuscules, tiret compris. */
    public const string PATTERN = '[2-9A-HJ-NP-TW-Za-hj-np-tw-z]{4}-[2-9A-HJ-NP-TW-Za-hj-np-tw-z]{4}';
}
