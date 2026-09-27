<?php

namespace App\Legal;

/**
 * Dates de révision des textes légaux : affichées sur les pages, données au sitemap, enregistrées avec chaque achat.
 */
final class LegalVersions
{
    /** Dernière révision des mentions légales et de la politique de confidentialité. */
    public const string UPDATED_AT = '2026-09-17';

    /**
     * Version des conditions générales de vente : la date de leur dernière révision. Enregistrée avec chaque achat
     * (Purchase::$termsVersion). À changer à chaque modification du texte de legal/terms.html.twig.
     */
    public const string TERMS_VERSION = '2026-09-17';
}
