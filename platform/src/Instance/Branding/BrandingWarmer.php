<?php

namespace App\Instance\Branding;

use App\Instance\Branding;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

/**
 * Lit la marque au démarrage (cache:clear, cache:warmup) : un marque.yaml invalide arrête le démarrage avec le
 * chemin de la clé en cause, plutôt que de casser la première page qui s'en sert.
 */
final readonly class BrandingWarmer implements CacheWarmerInterface
{
    public function __construct(
        private Branding $branding,
    ) {
    }

    public function isOptional(): bool
    {
        return true;
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        $this->branding->config();

        return [];
    }
}
