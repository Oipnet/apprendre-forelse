<?php

namespace App\Theme;

/**
 * Le thème à servir : ActiveTheme pour la requête en cours (choix de l'admin, aperçu), FixedTheme pour un dossier
 * donné (tests, vérification d'un thème précis).
 */
interface ThemeSelection
{
    /** « default » (le thème du moteur), « instance » (BRANDING_DIR) ou le nom d'un dossier de THEMES_DIR. */
    public function id(): string;

    /** Le dossier du thème ; vide pour le thème du moteur. */
    public function directory(): string;

    /** Vrai quand ce thème n'est montré qu'à l'administrateur qui le prévisualise. */
    public function isPreview(): bool;
}
