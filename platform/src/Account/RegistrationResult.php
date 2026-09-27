<?php

namespace App\Account;

/** Ce que l'inscription a fait, pour le message d'accueil. */
final readonly class RegistrationResult
{
    public function __construct(
        /** L'email de confirmation de l'adresse est parti. */
        public bool $confirmationSent,
    ) {
    }
}
