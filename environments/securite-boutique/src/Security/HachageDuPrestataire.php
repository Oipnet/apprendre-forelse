<?php

namespace App\Security;

/**
 * Le hachage « maison » laissé par le prestataire.
 *
 * FAILLE (chapitre 7, F7.1) : MD5 sans sel. Rapide à calculer, donc rapide à
 * casser ; deux clients avec le même mot de passe ont le même hachage (visible
 * à l'œil nu en base). Correction : password_hashers (auto = bcrypt/argon2) et
 * UserPasswordHasherInterface, avec une migration à la volée à la connexion.
 */
final class HachageDuPrestataire
{
    public function hacher(string $motDePasse): string
    {
        return md5($motDePasse);
    }

    public function verifier(string $motDePasse, string $hache): bool
    {
        return hash_equals($hache, md5($motDePasse));
    }
}
