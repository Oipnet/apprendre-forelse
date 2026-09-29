<?php

namespace App\Account\Github;

/** Ce que devient un retour de GitHub (voir GithubSignIn::resolve()). */
enum GithubSignInOutcome
{
    /** Compte retrouvé (lié à ce compte GitHub, ou par une adresse vérifiée des deux côtés) : on le connecte. */
    case SignedIn;
    /** Lié au compte connecté, à sa demande. */
    case Linked;
    /** Ni lien ni adresse connue : l'apprenant finalise son inscription (pseudo, code d'invitation). */
    case RegistrationNeeded;
    /** Ce compte GitHub est déjà lié à un autre compte de la plateforme. */
    case LinkedElsewhere;
    /** Le compte trouvé par l'adresse est déjà lié à un autre compte GitHub. */
    case OtherGithubAccount;
    /** Un compte existe avec cette adresse, mais il ne l'a pas confirmée : on ne le rattache pas d'office. */
    case UnverifiedAccount;
    /** GitHub n'a aucune adresse vérifiée pour ce compte : impossible d'en créer un. */
    case NoVerifiedEmail;
}
