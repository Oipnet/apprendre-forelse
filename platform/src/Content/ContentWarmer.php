<?php

namespace App\Content;

use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

/**
 * Lit les packs au démarrage (cache:clear, cache:warmup) et remplit le cache du contenu : sans lui, la première page
 * servie après un déploiement relisait et validait tous les YAML et Markdown des packs.
 *
 * Un pack invalide n'arrête pas le démarrage : les pages le signalent comme avant, et content:check dit quoi corriger.
 */
final readonly class ContentWarmer implements CacheWarmerInterface
{
    public function __construct(
        private ContentRepository $content,
    ) {
    }

    public function isOptional(): bool
    {
        return true;
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        try {
            $this->content->packs();
        } catch (ContentException) {
        }
        $this->content->reset();

        return [];
    }
}
