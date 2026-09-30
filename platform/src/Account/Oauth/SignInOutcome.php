<?php

namespace App\Account\Oauth;

/** Ce que devient un retour du fournisseur (voir ExternalSignIn::resolve()). */
enum SignInOutcome
{
    /** Compte retrouvé (lié à ce compte du fournisseur, ou par une adresse vérifiée des deux côtés) : on le connecte. */
    case SignedIn;
    /** Lié au compte connecté, à sa demande. */
    case Linked;
    /** Ni lien ni adresse connue : l'apprenant finalise son inscription (pseudo, code d'invitation). */
    case RegistrationNeeded;
    /** Ce compte du fournisseur est déjà lié à un autre compte de la plateforme. */
    case LinkedElsewhere;
    /** Le compte trouvé (ou connecté) est déjà lié à un autre compte chez ce fournisseur. */
    case OtherProviderAccount;
    /** Un compte existe avec cette adresse, mais il ne l'a pas confirmée : on ne le rattache pas d'office. */
    case UnverifiedAccount;
    /** Le fournisseur n'a aucune adresse vérifiée pour ce compte : impossible d'en créer un. */
    case NoVerifiedEmail;
}
