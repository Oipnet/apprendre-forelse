<?php

namespace App\Account;

/** L'issue d'un lien de confirmation d'adresse ouvert. */
enum EmailConfirmationOutcome
{
    /** Lien altéré ou expiré, ou compte inconnu. */
    case Invalid;
    case AlreadyConfirmed;
    /** Lien déjà servi, ou émis avant un autre changement du compte. */
    case Outdated;
    /** L'adresse a été prise par un autre compte entre-temps. */
    case EmailTaken;
    /** L'adresse du compte est confirmée, inchangée. */
    case Confirmed;
    /** Le compte utilise désormais la nouvelle adresse ; l'ancienne en est avertie. */
    case Changed;
}
