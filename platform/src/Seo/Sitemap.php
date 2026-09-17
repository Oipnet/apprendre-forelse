<?php

namespace App\Seo;

use App\Content\ContentRepository;
use App\Content\Practice;
use App\Content\Track;
use App\Controller\LegalController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Les pages que les moteurs de recherche doivent trouver, telles qu'un visiteur sans compte les voit : accueil,
 * parcours publiés et leurs exercices, Pratique publiée, pages légales. Rien d'un parcours en préparation ni d'un
 * exercice de Pratique programmé, quel que soit le compte qui demande le sitemap.
 *
 * La date de modification d'une page de contenu est celle du fichier le plus récent de son dossier dans le pack.
 */
final readonly class Sitemap
{
    /** Dernière révision des pages Contact et Écoles et entreprises (textes du moteur). */
    public const string PAGES_UPDATED_AT = '2026-09-17';

    public function __construct(
        private ContentRepository $content,
        private UrlGeneratorInterface $urls,
        #[Autowire(service: 'sitemap.cache')]
        private CacheInterface $cache,
    ) {
    }

    /** @return list<array{loc: string, lastmod: string}> lastmod au format AAAA-MM-JJ */
    public function entries(): array
    {
        return $this->cache->get('sitemap', function (ItemInterface $item): array {
            $entries = [];
            $latest = LegalController::UPDATED_AT;

            foreach ($this->publishedTracks() as $track) {
                $trackEntries = [];
                foreach ($this->content->exercisesOf($track) as $exercise) {
                    $trackEntries[] = $this->entry('app_exercise', ['trackId' => $track->id, 'exerciseId' => $exercise->id], self::lastModified($exercise->directory));
                }
                // Le parcours change quand track.yaml, une fiche ou un de ses exercices change.
                $modified = max(self::lastModified($track->directory), ...array_column($trackEntries, 'lastmod') ?: ['']);
                $entries[] = $this->entry('app_track', ['trackId' => $track->id], $modified);
                array_push($entries, ...$trackEntries);
                $latest = max($latest, $modified);
            }

            $practiceEntries = [];
            foreach ($this->publishedPractices() as $practice) {
                $practiceEntries[] = $this->entry('app_exercise_pratique', ['exerciseId' => $practice->exercise->id], self::practiceModified($practice));
            }
            if ($practiceEntries) {
                $practiceLatest = max(array_column($practiceEntries, 'lastmod'));
                $entries[] = $this->entry('app_practice', [], $practiceLatest);
                array_push($entries, ...$practiceEntries);
                $latest = max($latest, $practiceLatest);
            }

            $entries[] = $this->entry('app_organizations', [], self::PAGES_UPDATED_AT);
            $entries[] = $this->entry('app_contact', [], self::PAGES_UPDATED_AT);
            $entries[] = $this->entry('app_legal_notice', [], LegalController::UPDATED_AT);
            $entries[] = $this->entry('app_privacy', [], LegalController::UPDATED_AT);
            $entries[] = $this->entry('app_terms', [], LegalController::TERMS_VERSION);

            return [$this->entry('app_home', [], $latest), ...$entries];
        });
    }

    /** @return list<Track> les parcours publiés, dans l'ordre d'affichage */
    public function publishedTracks(): array
    {
        return array_values(array_filter($this->content->tracks(), static fn (Track $track) => !$track->isRestricted()));
    }

    /** @return list<Practice> */
    public function publishedPractices(): array
    {
        return array_values(array_filter($this->content->practices(), static fn (Practice $p) => !$p->isRestricted() && !$p->isScheduled()));
    }

    /** Un exercice de Pratique n'est pas modifié avant sa date de publication (dossier copié la veille, par exemple). */
    public static function practiceModified(Practice $practice): string
    {
        return max($practice->published->format('Y-m-d'), self::lastModified($practice->exercise->directory));
    }

    /** Date du fichier le plus récent du dossier (récursivement), au format AAAA-MM-JJ. */
    public static function lastModified(string $directory): string
    {
        $latest = is_dir($directory) ? (int) filemtime($directory) : 0;
        if (is_dir($directory)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $latest = max($latest, $file->getMTime());
            }
        }

        return date('Y-m-d', $latest);
    }

    /**
     * @param array<string, string> $parameters
     *
     * @return array{loc: string, lastmod: string}
     */
    private function entry(string $route, array $parameters, string $lastmod): array
    {
        return ['loc' => $this->urls->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_URL), 'lastmod' => $lastmod];
    }
}
