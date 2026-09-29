<?php

namespace App\Account\Oauth;

use App\Entity\User;

final readonly class SignInResult
{
    public function __construct(
        public SignInOutcome $outcome,
        /** Le compte à connecter (SignedIn) ou qui vient d'être lié (Linked). */
        public ?User $user = null,
    ) {
    }
}
