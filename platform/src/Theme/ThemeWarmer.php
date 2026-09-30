<?php

namespace App\Theme;

use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

/**
 * Lit le thème au démarrage (cache:clear, cache:warmup) : un theme.yaml invalide arrête le démarrage avec le
 * chemin de la clé en cause, plutôt que de casser la première page qui s'en sert.
 */
final readonly class ThemeWarmer implements CacheWarmerInterface
{
    public function __construct(
        private Theme $theme,
    ) {
    }

    public function isOptional(): bool
    {
        return true;
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        $this->theme->config();

        return [];
    }
}
