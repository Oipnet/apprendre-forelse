<?php

namespace App\Account\Oauth;

/** Le fournisseur a refusé l'échange, ou n'a pas répondu : la connexion n'aboutit pas, l'apprenant peut réessayer. */
final class OauthException extends \RuntimeException
{
}
