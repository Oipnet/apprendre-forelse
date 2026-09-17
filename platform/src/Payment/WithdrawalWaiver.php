<?php

namespace App\Payment;

/**
 * Renonciation au droit de rétractation pour un contenu numérique fourni immédiatement (art. L221-28 13° du
 * Code de la consommation). Le texte coché est conservé tel quel avec l'achat, avec l'heure d'acceptation :
 * s'il change ici, les achats passés gardent celui qu'ils ont accepté.
 */
final class WithdrawalWaiver
{
    public const string TEXT = 'Je demande l\'accès immédiat au parcours et je reconnais perdre mon droit de rétractation dès le début de son exécution, s\'agissant d\'un contenu numérique fourni sans support matériel.';
}
