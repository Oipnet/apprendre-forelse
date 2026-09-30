<?php

namespace App\Content;

use App\Version;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Charge les packs (PackReader), en passant par le cache (pool content.cache) : sans lui, chaque page relisait et
 * validait tous les YAML et Markdown des packs. Le cache reste valable tant que chaque fichier et dossier lu garde sa
 * date et sa taille (voir PackFiles::watch()) : un pack déposé, modifié ou retiré se voit à la requête suivante, sans
 * redémarrage.
 */
final readonly class PackLoader
{
    /** À augmenter quand LoadedContent change de forme : un cache d'avant n'a pas la nouvelle clé. */
    private const int CACHE_FORMAT = 2;

    /**
     * @param list<string> $packPaths dossiers de packs, ou dossiers contenant des packs
     */
    public function __construct(
        private array $packPaths,
        private EnvironmentRegistry $environments,
        private Version $version,
        /** Sans pool (tests, outils), le contenu est relu à chaque chargement. */
        private ?CacheItemPoolInterface $cache = null,
    ) {
    }

    public function load(): LoadedContent
    {
        $item = $this->cache?->getItem($this->cacheKey());
        $cached = $item?->isHit() ? $item->get() : null;
        if (\is_array($cached) && $this->isFresh($cached['watched'])) {
            return LoadedContent::fromArray($cached);
        }

        $content = (new PackReader($this->packPaths, $this->environments, $this->version))->read();
        if (null !== $item) {
            $this->cache->save($item->set($content->toArray()));
        }

        return $content;
    }

    /** Oublie le contenu mis en cache : à appeler après avoir écrit dans un pack (voir l'atelier). */
    public function forget(): void
    {
        $this->cache?->deleteItem($this->cacheKey());
    }

    /** Une entrée par version du moteur et par jeu de chemins (les tests en changent) : un vieux cache ne resert jamais. */
    private function cacheKey(): string
    {
        return 'packs.'.hash('xxh128', serialize([self::CACHE_FORMAT, $this->version->get(), $this->packPaths, $this->environments->roots()]));
    }

    /** @param array<string, string> $watched */
    private function isFresh(array $watched): bool
    {
        foreach ($watched as $path => $fingerprint) {
            if (PackFiles::fingerprint($path) !== $fingerprint) {
                return false;
            }
        }

        return true;
    }
}
