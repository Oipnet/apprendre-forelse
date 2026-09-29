<?php

namespace App\Account;

/** L'issue d'une demande de changement d'adresse (ou d'un nouvel envoi du lien de confirmation). */
enum EmailChangeOutcome
{
    /** Rien à confirmer : l'adresse ne change pas (le pseudo est enregistré). */
    case Saved;
    /**
     * L'adresse change sans le bon mot de passe actuel (ou, sans mot de passe, sans connexion GitHub récente) : rien
     * n'est dit de la nouvelle adresse.
     */
    case PasswordRequired;
    case EmailTaken;
    /** L'adresse est déjà confirmée : rien à renvoyer. */
    case AlreadyConfirmed;
    /** Trop de liens envoyés pour ce compte. */
    case Throttled;
    case Sent;
    case SendFailed;
}
