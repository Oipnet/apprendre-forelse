<?php

namespace App;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Version du moteur, lue dans le fichier VERSION à la racine du dépôt (source unique de vérité).
 *
 * Elle suit le versionnage sémantique : voir la section « Versionnage » du README pour ce qu'un
 * changement majeur, mineur ou correctif engage vis-à-vis des packs et des instances auto-hébergées.
 */
final class Version implements \Stringable
{
    /** Quand le fichier VERSION est absent (checkout partiel, image mal construite). */
    public const string INCONNUE = '0.0.0';

    private ?string $version = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/../VERSION')]
        private readonly string $file,
    ) {
    }

    public function get(): string
    {
        return $this->version ??= is_file($this->file)
            ? (trim((string) file_get_contents($this->file)) ?: self::INCONNUE)
            : self::INCONNUE;
    }

    public function __toString(): string
    {
        return $this->get();
    }
}
