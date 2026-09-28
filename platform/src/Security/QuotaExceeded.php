<?php

namespace App\Security;

/** Trop de demandes en peu de temps (messages, avis…) : rien n'a été enregistré. Le message s'adresse à l'utilisateur. */
final class QuotaExceeded extends \RuntimeException
{
}
