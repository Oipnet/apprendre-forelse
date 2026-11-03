<?php

namespace App\Scratch;

/** Combien de temps un lien court du bac à sable reste valable : choisi au partage. Par défaut, jamais : un lien posté sur un forum doit continuer de marcher. */
enum ScratchShareDuration: string
{
    case OneMonth = '1m';
    case SixMonths = '6m';
    case OneYear = '1a';
    case Never = 'jamais';

    /** La date d'expiration ; null pour un lien qui n'expire jamais (il reste supprimable par son créateur). */
    public function expiresAt(\DateTimeImmutable $from): ?\DateTimeImmutable
    {
        return match ($this) {
            self::OneMonth => $from->modify('+1 month'),
            self::SixMonths => $from->modify('+6 months'),
            self::OneYear => $from->modify('+1 year'),
            self::Never => null,
        };
    }
}
