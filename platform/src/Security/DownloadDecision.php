<?php

namespace App\Security;

/** Ce que devient la demande d'un fichier à télécharger (voir DownloadPolicy). */
enum DownloadDecision
{
    case Allowed;
    /** Le fichier accompagne des parcours invisibles pour ce compte : il n'existe pas pour lui. */
    case Hidden;
    /** Le fichier accompagne un parcours visible, auquel le compte n'a pas accès. */
    case Denied;
}
