<?php

namespace App\Seo;

use App\Content\Article;
use App\Content\ConceptIndex;
use App\Content\ContentDates;
use App\Content\ContentRepository;
use App\Content\Exercise;
use App\Content\PracticeVersionIndex;
use App\Content\PublishedContent;
use App\Instance\SelfHostingPage;
use App\Legal\LegalVersions;
use App\Scratch\ScratchFramework;
use App\Theme\Theme;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Les pages que les moteurs de recherche doivent trouver, telles qu'un visiteur sans compte les voit : accueil,
 * parcours publiés et leurs exercices, Pratique publiée, articles parus, pages légales. Rien d'un parcours en préparation ni d'un
 * exercice de Pratique programmé, quel que soit le compte qui demande le sitemap.
 *
 * La date de modification d'une page de contenu est celle du fichier le plus récent de son dossier dans le pack
 * (voir ContentDates) ; celle d'un article, la date que son auteur déclare (voir Article::modified()).
 */
final readonly class Sitemap
{
    /** Dernière révision des pages Contact, Écoles et entreprises et Auto-hébergement (textes du moteur). */
    public const string PAGES_UPDATED_AT = '2026-09-17';

    /** Dernière révision des pages du bac à sable (textes et projets de départ du moteur). */
    public const string SCRATCH_UPDATED_AT = '2026-10-27';

    public function __construct(
        private ContentRepository $content,
        private PublishedContent $published,
        private ConceptIndex $concepts,
        private PracticeVersionIndex $versions,
        private UrlGeneratorInterface $urls,
        #[Autowire(service: 'sitemap.cache')]
        private CacheInterface $cache,
        private SelfHostingPage $selfHosting,
        private ContentDates $dates,
        private Theme $theme,
        /** @var list<string> */
        #[Autowire('%kernel.enabled_locales%')]
        private array $locales = ['fr'],
    ) {
    }

    /** @return list<array{loc: string, lastmod: string}> lastmod au format AAAA-MM-JJ */
    public function entries(): array
    {
        // L'accueil dans chaque langue que le thème parle : changer de thème change la liste.
        $homes = array_values(array_filter($this->locales, $this->theme->speaks(...)));

        return $this->cache->get('sitemap.'.implode('-', $homes), function (ItemInterface $item) use ($homes): array {
            $entries = [];
            $latest = LegalVersions::UPDATED_AT;

            // Les parcours dans chaque langue où ils sont traduits : /parcours, puis /en/tracks (et le catalogue anglais
            // dès qu'un parcours l'est).
            foreach ([Exercise::LOCALE, ...Exercise::TRANSLATIONS] as $locale) {
                $tracksLatest = null;
                $localeEntries = [];
                foreach ($this->published->tracks() as $published) {
                    $track = $this->content->findTrack($published->id, $locale);
                    if (null === $track) {
                        continue;
                    }
                    $trackEntries = [];
                    foreach ($this->content->exercisesOf($track) as $exercise) {
                        $trackEntries[] = $this->entry('app_exercise', ['_locale' => $locale, 'trackId' => $track->id, 'exerciseId' => $exercise->id], $this->dates->lastModified($exercise->directory));
                    }
                    // Le sommaire d'un chapitre change quand un de ses exercices change.
                    $chapterEntries = [];
                    foreach ($track->chapters as $chapter) {
                        $dates = array_map(fn (Exercise $exercise) => $this->dates->lastModified($exercise->directory), $this->content->exercisesOfChapter($track, $chapter));
                        // Un chapitre sans exercice lisible n'a pas de sommaire (voir ChapterOutline).
                        if ([] !== $dates) {
                            $chapterEntries[] = $this->entry('app_chapter_summary', ['_locale' => $locale, 'trackId' => $track->id, 'chapterId' => $chapter->id], max($dates));
                        }
                    }

                    // Le parcours change quand track.yaml, une fiche ou un de ses exercices change.
                    $modified = max($this->dates->lastModified($published->directory), ...array_column($trackEntries, 'lastmod') ?: ['']);
                    $localeEntries[] = $this->entry('app_track', ['_locale' => $locale, 'trackId' => $track->id], $modified);
                    array_push($localeEntries, ...$chapterEntries, ...$trackEntries);
                    $latest = max($latest, $modified);
                    $tracksLatest = max($tracksLatest ?? '', $modified);
                }
                // Le catalogue change quand un parcours change.
                if (null !== $tracksLatest) {
                    array_push($entries, $this->entry('app_tracks', ['_locale' => $locale], $tracksLatest), ...$localeEntries);
                }
            }

            // La Pratique dans chaque langue où un exercice est traduit : /pratique, puis /en/practice.
            foreach ([Exercise::LOCALE, ...Exercise::TRANSLATIONS] as $locale) {
                $practiceEntries = [];
                foreach ($this->published->practices($locale) as $practice) {
                    $practiceEntries[] = $this->entry('app_exercise_pratique', ['_locale' => $locale, 'exerciseId' => $practice->exercise->id], $this->dates->practiceModified($practice));
                }
                if ($practiceEntries) {
                    $practiceLatest = max(array_column($practiceEntries, 'lastmod'));
                    $entries[] = $this->entry('app_practice', ['_locale' => $locale], $practiceLatest);
                    array_push($entries, ...$practiceEntries);
                    $latest = max($latest, $practiceLatest);
                }
            }

            // Les nouveautés d'une version : une page par version qu'au moins deux exercices pratiquent.
            foreach ($this->versions->practices() as $slug => $practices) {
                $dates = array_map($this->dates->practiceModified(...), $practices);
                // Une intro réécrite change la page, sans qu'aucun exercice bouge.
                if (null !== ($intro = $this->versions->introFile($slug))) {
                    $dates[] = date('Y-m-d', (int) filemtime($intro));
                }
                $entries[] = $this->entry('app_practice_version', ['slug' => $slug], max($dates));
            }

            // Les notions : une page par notion travaillée par plus d'un exercice, datée de son contenu. Seules celles
            // qui ont une intro écrite s'indexent (voir ConceptController) : l'index les liste toutes, le sitemap non.
            // Dans chaque langue qui a des notions : /notions, puis /en/concepts.
            foreach ([Exercise::LOCALE, ...Exercise::TRANSLATIONS] as $locale) {
                $conceptEntries = $conceptDates = [];
                foreach ($this->concepts->directories($locale) as $slug => $directories) {
                    $dates = array_map($this->dates->lastModified(...), $directories);
                    if (null !== ($intro = $this->concepts->introFile((string) $slug, $locale))) {
                        // Une intro réécrite change la page, sans qu'aucun exercice bouge.
                        $dates[] = date('Y-m-d', (int) filemtime($intro));
                        $conceptEntries[] = $this->entry('app_concept', ['_locale' => $locale, 'slug' => (string) $slug], max($dates));
                    }
                    $conceptDates[] = max($dates);
                }
                if ($conceptDates) {
                    $entries[] = $this->entry('app_concepts', ['_locale' => $locale], max($conceptDates));
                    array_push($entries, ...$conceptEntries);
                }
            }

            // Le blog dans chaque langue où un article a paru : /blog, puis /en/blog.
            foreach ([Article::LOCALE, ...Article::TRANSLATIONS] as $locale) {
                $articleEntries = array_map(fn (Article $article) => $this->entry('app_article', ['_locale' => $locale, 'slug' => $article->slug], $article->modified()), $this->published->articles($locale));
                if ($articleEntries) {
                    $entries[] = $this->entry('app_blog', ['_locale' => $locale], max(array_column($articleEntries, 'lastmod')));
                    array_push($entries, ...$articleEntries);
                }
            }

            // Le bac à sable, en français et en anglais : le choix du framework, puis un projet par framework.
            foreach ([Exercise::LOCALE, ...Exercise::TRANSLATIONS] as $locale) {
                $entries[] = $this->entry('app_scratch', ['_locale' => $locale], self::SCRATCH_UPDATED_AT);
                foreach (ScratchFramework::cases() as $framework) {
                    $entries[] = $this->entry('app_scratch_'.$framework->value, ['_locale' => $locale], self::SCRATCH_UPDATED_AT);
                }
            }

            $entries[] = $this->entry('app_organizations', [], self::PAGES_UPDATED_AT);
            if ($this->selfHosting->enabled()) {
                $entries[] = $this->entry('app_self_hosting', [], self::PAGES_UPDATED_AT);
            }
            $entries[] = $this->entry('app_contact', [], self::PAGES_UPDATED_AT);
            $entries[] = $this->entry('app_legal_notice', [], LegalVersions::UPDATED_AT);
            $entries[] = $this->entry('app_privacy', [], LegalVersions::UPDATED_AT);
            $entries[] = $this->entry('app_terms', [], LegalVersions::TERMS_VERSION);

            return [...array_map(fn (string $locale) => $this->entry('app_home', ['_locale' => $locale], $latest), $homes), ...$entries];
        });
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
