<?php

namespace App\Instance;

use App\Theme\ThemeTemplateLoader;

/**
 * Page « Auto-hébergement » : le moteur ne la rédige pas. Elle n'existe que si le thème actif en fournit le gabarit
 * (self_hosting/index.html.twig) ; sinon /auto-hebergement répond 404, et ni le pied de page ni le sitemap n'y mènent.
 * Les adresses du dépôt, du guide et de la licence restent au moteur : ce sont les siennes.
 */
final readonly class SelfHostingPage
{
    public const string TEMPLATE = 'self_hosting/index.html.twig';
    public const string REPOSITORY = 'https://github.com/oipnet/apprendre-forelse';
    public const string GUIDE = self::REPOSITORY.'/blob/main/auto-hebergement/README.md';
    public const string LICENSE = self::REPOSITORY.'/blob/main/LICENSE';

    public function __construct(
        private ThemeTemplateLoader $themeTemplates,
    ) {
    }

    /** Vrai quand le thème actif fournit la page (le chargeur des gabarits du thème suit le thème de la requête). */
    public function enabled(): bool
    {
        return $this->themeTemplates->exists(self::TEMPLATE);
    }
}
