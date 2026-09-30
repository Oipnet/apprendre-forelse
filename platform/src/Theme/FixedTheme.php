<?php

namespace App\Theme;

/** Un thème désigné par son dossier, sans choix ni aperçu : vide, c'est le thème du moteur. */
final readonly class FixedTheme implements ThemeSelection
{
    public function __construct(
        private string $directory = '',
        private ?string $id = null,
    ) {
    }

    public function id(): string
    {
        return $this->id ?? ('' === $this->directory ? ThemeCatalog::DEFAULT : ThemeCatalog::INSTANCE);
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function isPreview(): bool
    {
        return false;
    }
}
