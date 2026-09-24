<?php

namespace App\Account;

/**
 * Demande de nouveau mot de passe pour une adresse, traitée par le worker (SendPasswordResetLink).
 *
 * La requête HTTP ne fait que déposer ce message, que l'adresse ait un compte ou non : même travail, même
 * temps de réponse. Chercher le compte, créer le jeton et écrire l'email se font hors de la requête.
 */
final readonly class PasswordResetRequested
{
    public function __construct(
        public string $email,
    ) {
    }
}
