<?php

namespace App\Account\Github;

/** GitHub a refusé l'échange, ou n'a pas répondu : la connexion n'aboutit pas, l'apprenant peut réessayer. */
final class GithubException extends \RuntimeException
{
}
