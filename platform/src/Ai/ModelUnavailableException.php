<?php

namespace App\Ai;

use App\Content\ContentException;

/**
 * Le modèle ne peut pas répondre (pas de clé, API injoignable ou en erreur) : ni l'auteur ni l'apprenant n'y sont pour rien.
 *
 * Sous-classe de ContentException le temps que les appelants distinguent les deux cas.
 */
final class ModelUnavailableException extends ContentException
{
}
