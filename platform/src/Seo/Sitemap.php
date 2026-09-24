<?php

namespace App\Seo;

use App\Content\ConceptIndex;
use App\Content\ContentRepository;
use App\Content\Practice;
use App\Content\PracticeVersionIndex;
use App\Content\PublishedContent;
use App\Controller\LegalController;
use App\Instance\SelfHostingPage;
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
    /** Dernière révision des pages Contact, Écoles et entreprises et Auto-hébergement (textes du moteur). */
    public const string PAGES_UPDATED_AT = '2026-09-17';

    public function __construct(
        private ContentRepository $content,
        private PublishedContent $published,
        private ConceptIndex $concepts,
        private PracticeVersionIndex $versions,
        private UrlGeneratorInterface $urls,
        #[Autowire(service: 'sitemap.cache')]
        private CacheInterface $cache,
        private SelfHostingPage $selfHosting,
    ) {
    }

    /** @return list<array{loc: string, lastmod: string}> lastmod au format AAAA-MM-JJ */
    public function entries(): array
    {
        return $this->cache->get('sitemap', function (ItemInterface $item): array {
            $entries = [];
            $latest = LegalController::UPDATED_AT;

            foreach ($this->published->tracks() as $track) {
                $trackEntries = [];
                foreach ($this->content->exercisesOf($track) as $exercise) {
                    $trackEntries[] = $this->entry('app_exercise', ['trackId' => $track->id, 'exerciseId' => $exercise->id], self::lastModified($exercise->directory));
                }
                // Le sommaire d'un chapitre change quand un de ses exercices change.
                $chapterEntries = [];
                foreach ($track->chapters as $chapter) {
                    $dates = [];
                    foreach ($chapter->exerciseIds as $exerciseId) {
                        $exercise = $this->content->findExercise($track->id, $exerciseId);
                        $dates[] = null === $exercise ? null : self::lastModified($exercise->directory);
                    }
                    $dates = array_filter($dates);
                    // Un chapitre sans exercice lisible n'a pas de sommaire (voir ChapterOutline).
                    if ([] !== $dates) {
                        $chapterEntries[] = $this->entry('app_chapter_summary', ['trackId' => $track->id, 'chapterId' => $chapter->id], max($dates));
                    }
                }

                // Le parcours change quand track.yaml, une fiche ou un de ses exercices change.
                $modified = max(self::lastModified($track->directory), ...array_column($trackEntries, 'lastmod') ?: ['']);
                $entries[] = $this->entry('app_track', ['trackId' => $track->id], $modified);
                array_push($entries, ...$chapterEntries, ...$trackEntries);
                $latest = max($latest, $modified);
            }

            $practiceEntries = [];
            foreach ($this->published->practices() as $practice) {
                $practiceEntries[] = $this->entry('app_exercise_pratique', ['exerciseId' => $practice->exercise->id], self::practiceModified($practice));
            }
            if ($practiceEntries) {
                $practiceLatest = max(array_column($practiceEntries, 'lastmod'));
                $entries[] = $this->entry('app_practice', [], $practiceLatest);
                array_push($entries, ...$practiceEntries);
                $latest = max($latest, $practiceLatest);
            }

            // Les nouveautés d'une version : une page par version qu'au moins deux exercices pratiquent.
            foreach ($this->versions->practices() as $slug => $practices) {
                $dates = array_map(self::practiceModified(...), $practices);
                // Une intro réécrite change la page, sans qu'aucun exercice bouge.
                if (null !== ($intro = $this->versions->introFile($slug))) {
                    $dates[] = date('Y-m-d', (int) filemtime($intro));
                }
                $entries[] = $this->entry('app_practice_version', ['slug' => $slug], max($dates));
            }

            // Les notions : une page par notion travaillée par plus d'un exercice, datée de son contenu.
            $conceptEntries = [];
            foreach ($this->concepts->directories() as $slug => $directories) {
                $conceptEntries[] = $this->entry('app_concept', ['slug' => $slug], max(array_map(self::lastModified(...), $directories)));
            }
            if ($conceptEntries) {
                $entries[] = $this->entry('app_concepts', [], max(array_column($conceptEntries, 'lastmod')));
                array_push($entries, ...$conceptEntries);
            }

            $entries[] = $this->entry('app_organizations', [], self::PAGES_UPDATED_AT);
            if ($this->selfHosting->enabled) {
                $entries[] = $this->entry('app_self_hosting', [], self::PAGES_UPDATED_AT);
            }
            $entries[] = $this->entry('app_contact', [], self::PAGES_UPDATED_AT);
            $entries[] = $this->entry('app_legal_notice', [], LegalController::UPDATED_AT);
            $entries[] = $this->entry('app_privacy', [], LegalController::UPDATED_AT);
            $entries[] = $this->entry('app_terms', [], LegalController::TERMS_VERSION);

            return [$this->entry('app_home', [], $latest), ...$entries];
        });
    }

    /** Un exercice de Pratique n'est pas modifié avant sa date de publication (dossier copié la veille, par exemple). */
    public static function practiceModified(Practice $practice): string
    {
        return max($practice->published->format('Y-m-d'), self::lastModified($practice->exercise->directory));
    }

    /**
     * Date du fichier le plus récent du dossier (récursivement), au format AAAA-MM-JJ.
     *
     * Elle n'a de sens que si l'installation des packs conserve les dates réelles : git n'en garde aucune,
     * et une récupération du dépôt les met toutes à l'heure de la récupération. Le déploiement des packs
     * les rétablit donc depuis le journal avant de copier (voir .github/workflows/contenu.yml du dépôt de
     * contenu). Un pack déposé à la main annonce, lui, la date du dépôt : sans conséquence pour une
     * instance qui ne s'indexe pas (SEARCH_INDEXING=0).
     */
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
