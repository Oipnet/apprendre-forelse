<?php

namespace App\Content;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Les dates de modification du contenu, lues sur le disque : celle du fichier le plus récent d'un dossier.
 *
 * Parcourir un dossier récursivement coûte : les dates publiées (sitemap, données structurées d'une page)
 * sont gardées une heure dans le cache du sitemap. L'atelier, lui, lit la date du moment.
 *
 * Elles n'ont de sens que si l'installation des packs conserve les dates réelles : git n'en garde aucune,
 * et une récupération du dépôt les met toutes à l'heure de la récupération. Le déploiement des packs
 * les rétablit donc depuis le journal avant de copier (voir .github/workflows/contenu.yml du dépôt de
 * contenu). Un pack déposé à la main annonce, lui, la date du dépôt : sans conséquence pour une
 * instance qui ne s'indexe pas (SEARCH_INDEXING=0).
 */
final readonly class ContentDates
{
    public function __construct(
        #[Autowire(service: 'sitemap.cache')]
        private CacheInterface $cache,
    ) {
    }

    /** Date du fichier le plus récent du dossier (récursivement, le dossier compris), au format AAAA-MM-JJ. */
    public function lastModified(string $directory): string
    {
        return $this->cache->get('content-dates.'.hash('xxh128', $directory), static function (ItemInterface $item) use ($directory): string {
            $latest = is_dir($directory) ? max((int) filemtime($directory), self::latestFile($directory)) : 0;

            return date('Y-m-d', $latest);
        });
    }

    /** Un exercice de Pratique n'est pas modifié avant sa date de publication (dossier copié la veille, par exemple). */
    public function practiceModified(Practice $practice): string
    {
        return max($practice->published->format('Y-m-d'), $this->lastModified($practice->exercise->directory));
    }

    /** Le fichier du dossier modifié le plus récemment, lu à l'instant (sans cache) ; null sans fichier. */
    public function latestFileNow(string $directory): ?\DateTimeImmutable
    {
        $latest = is_dir($directory) ? self::latestFile($directory) : 0;

        return $latest > 0 ? (new \DateTimeImmutable())->setTimestamp($latest) : null;
    }

    private static function latestFile(string $directory): int
    {
        $latest = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $latest = max($latest, $file->getMTime());
        }

        return $latest;
    }
}
