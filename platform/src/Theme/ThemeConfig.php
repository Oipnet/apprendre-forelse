<?php

namespace App\Theme;

/**
 * Le thème de l'instance, lu et vérifié en une fois par ThemeLoader : tout ce qui est ici est valable.
 */
final readonly class ThemeConfig
{
    /**
     * @param array<string, string|null>                                                                     $images    fichier du dossier du thème par rôle (logo, icon, share), null si non déclaré
     * @param array<string, string>                                                                          $colors    variable CSS => couleur, thème clair
     * @param array<string, string>                                                                          $editor    variable CSS => couleur, thème sombre de l'éditeur
     * @param array<string, string>                                                                          $fonts     variable CSS => pile de polices
     * @param array{showcase: array<string, mixed>|null, author: array<string, mixed>|null, demo: array<string, mixed>|null} $home
     * @param list<string>                                                                                   $stylesheets feuilles du thème (« assets/theme.css »), posées après celle du moteur
     * @param list<string>                                                                                   $scripts     scripts du thème, chargés après ceux du moteur
     * @param list<string>                                                                                   $preload     polices du thème à précharger
     * @param array{name: string, jobTitle: string}|null                                                     $person    la personne derrière les contenus (marque du moteur seulement)
     */
    public function __construct(
        /** Vrai quand aucun theme.yaml n'est monté : c'est le thème du moteur. */
        public bool $isDefault,
        public string $directory,
        /** Le fichier lu : theme.yaml, ou l'ancien marque.yaml encore accepté jusqu'à la 4.0. */
        public string $file,
        public string $name,
        public string $chip,
        public string $title,
        public string $tagline,
        public string $url,
        public array $images,
        public array $colors,
        public array $editor,
        public array $fonts,
        public array $home,
        public ?array $person = null,
        public array $stylesheets = [],
        public array $scripts = [],
        public array $preload = [],
        /** Vrai quand les feuilles du thème remplacent celle du moteur, au lieu de s'y ajouter. */
        public bool $replacesEngineStyles = false,
    ) {
    }
}
