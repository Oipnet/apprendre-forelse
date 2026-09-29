<?php

namespace App\Account\Github;

use App\Entity\User;

final readonly class GithubSignInResult
{
    public function __construct(
        public GithubSignInOutcome $outcome,
        /** Le compte à connecter (SignedIn) ou qui vient d'être lié (Linked). */
        public ?User $user = null,
    ) {
    }
}
