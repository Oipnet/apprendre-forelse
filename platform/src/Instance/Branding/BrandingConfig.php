<?php

namespace App\Instance\Branding;

/**
 * La marque de l'instance, lue et vérifiée en une fois par BrandingLoader : tout ce qui est ici est valable.
 */
final readonly class BrandingConfig
{
    /**
     * @param array<string, string|null>                                                                     $images    fichier du dossier de marque par rôle (logo, icon, share), null si non déclaré
     * @param array<string, string>                                                                          $colors    variable CSS => couleur, thème clair
     * @param array<string, string>                                                                          $editor    variable CSS => couleur, thème sombre de l'éditeur
     * @param array<string, string>                                                                          $fonts     variable CSS => pile de polices
     * @param array{showcase: array<string, mixed>|null, author: array<string, mixed>|null, demo: array<string, mixed>|null} $home
     * @param array{name: string, jobTitle: string}|null                                                     $person    la personne derrière les contenus (marque du moteur seulement)
     */
    public function __construct(
        /** Vrai quand aucun marque.yaml n'est monté : c'est la marque du moteur. */
        public bool $isDefault,
        public string $directory,
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
    ) {
    }
}
