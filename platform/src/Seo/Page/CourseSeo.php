<?php

namespace App\Seo\Page;

use App\Content\Chapter;
use App\Content\ContentRepository;
use App\Content\EnvironmentRegistry;
use App\Content\Exercise;
use App\Content\ExerciseStory;
use App\Content\Track;
use App\Theme\Theme;
use App\Payment\TrackOfferFactory;
use App\Seo\PageSeo;
use App\Seo\SchemaOrg;
use App\Seo\TrackSeoText;
use App\Twig\DurationExtension;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Les pages d'un parcours : le parcours (Course), le sommaire d'un chapitre et un exercice. Les titres mènent
 * par les notions travaillées, que l'on cherche dans un moteur, et nomment le framework.
 */
final readonly class CourseSeo
{
    public function __construct(
        private PageSeo $seo,
        private SchemaOrg $schema,
        private EnvironmentRegistry $environments,
        private TrackOfferFactory $offers,
        private TrackSeoText $trackText,
        private ContentRepository $content,
        private Theme $theme,
        private ExerciseStory $stories,
        private TranslatorInterface $translator,
        /** @var list<string> */
        #[Autowire('%kernel.enabled_locales%')]
        private array $locales = [Exercise::LOCALE],
    ) {
    }

    public function track(Track $track): void
    {
        $this->seo
            ->setFullTitle($this->trackText->title($track, $this->framework($track->environment)))
            ->setDescription($this->trackText->description($track))
            ->setCanonical($url = $this->schema->url('app_track', ['_locale' => $track->locale, 'trackId' => $track->id]));
        $this->alternates($track, 'app_track', ['trackId' => $track->id]);

        // Le prix affiché à un visiteur : prix courant (fondateur compris), en euros TTC.
        $offer = $this->offers->create($track, null);
        $duration = null === ($minutes = $this->content->durationOf($track)) ? null : DurationExtension::iso($minutes);
        $this->shareImage($track);
        // Google exige une image : celle du parcours, sinon celle du partage de la marque, à défaut le logo.
        $image = $this->shareImageUrl($track) ?? $this->theme->shareUrl() ?? $this->theme->logoLargeUrl() ?? $this->theme->logoUrl();
        $this->seo
            ->addStructuredData([
                '@type' => 'Course',
                // Désigné par ses exercices (LearningResource, isPartOf).
                '@id' => $url.'#cours',
                'name' => $track->title,
                'description' => self::plain($track->description),
                'url' => $url,
                ...(null === $track->level ? [] : ['educationalLevel' => $this->trans('parcours.niveau.'.$track->level)]),
                'inLanguage' => $this->seo->getLanguage(),
                'provider' => $this->schema->organization(),
                ...(null === $image ? [] : ['image' => $this->schema->absolute($image)]),
                // Le résultat enrichi « Cours » de Google demande la charge de travail sur chaque session (courseWorkload) ;
                // timeRequired, au niveau du cours, ne la remplace pas.
                'hasCourseInstance' => [
                    '@type' => 'CourseInstance',
                    'courseMode' => 'Online',
                    'inLanguage' => $this->seo->getLanguage(),
                    ...(null === $duration ? [] : ['courseWorkload' => $duration]),
                ],
                ...(null === $duration ? [] : ['timeRequired' => $duration]),
                'syllabusSections' => array_map(static fn (Chapter $chapter) => [
                    '@type' => 'Syllabus',
                    'name' => $chapter->title,
                    ...(null === $chapter->description ? [] : ['description' => $chapter->description]),
                ], $track->chapters),
                'offers' => [
                    '@type' => 'Offer',
                    'category' => $offer->quote->isFree() ? 'Free' : 'Paid',
                    'price' => number_format($offer->quote->price / 100, 2, '.', ''),
                    'priceCurrency' => 'EUR',
                    'url' => $url,
                    'availability' => $offer->quote->isFree() || $offer->paymentsEnabled ? 'https://schema.org/InStock' : 'https://schema.org/PreOrder',
                ],
            ])
            ->addStructuredData($this->schema->breadcrumb([[$track->title, $url]]));
    }

    /**
     * Le sommaire d'un chapitre : ce qu'il fait apprendre, et combien d'exercices l'y amènent.
     *
     * @param list<string> $concepts les notions de ses exercices, sans doublon
     */
    public function chapter(Track $track, Chapter $chapter, int $number, array $concepts): void
    {
        $framework = $this->framework($track->environmentFor($chapter));
        $count = \count($chapter->exerciseIds);
        $this->seo
            ->setTitle(
                ...$this->conceptLed($concepts, $framework, $chapter->title),
                ...[$this->trans('chapitre.titre', ['chapitre' => $chapter->title, 'numero' => $number, 'framework' => $framework]), $chapter->title],
            )
            ->setDescription(
                // La description écrite pour le chapitre, quand il en a une (clé « description ») ; sinon, ce qu'il fait travailler.
                $chapter->description ?? $this->trans('chapitre.description', [
                    'numero' => $number,
                    'parcours' => $track->title,
                    'notions' => $concepts ? mb_strtolower(implode(', ', \array_slice($concepts, 0, 4))) : mb_strtolower($chapter->title),
                    'n' => $count,
                ]),
            )
            ->setCanonical($url = $this->schema->url('app_chapter_summary', ['_locale' => $track->locale, 'trackId' => $track->id, 'chapterId' => $chapter->id]))
            ->addStructuredData($this->schema->breadcrumb([
                [$track->title, $this->schema->url('app_track', ['_locale' => $track->locale, 'trackId' => $track->id])],
                [$chapter->title, $url],
            ]));
        $this->alternates($track, 'app_chapter_summary', ['trackId' => $track->id, 'chapterId' => $chapter->id]);
        $this->shareImage($track);
    }

    public function exercise(Track $track, Chapter $chapter, Exercise $exercise): void
    {
        $framework = $this->framework($exercise->environment);
        $this->seo
            ->setTitle(
                ...$this->conceptLed($exercise->concepts, $framework, $this->trans('exercice.libelle', ['exercice' => $exercise->title])),
                ...[$this->trans('exercice.titre', ['exercice' => $exercise->title, 'framework' => $framework]), $exercise->title],
            )
            ->setDescription(
                $this->stories->fromInstructions($exercise->instructions),
                $this->trans('exercice.complement', ['chapitre' => $chapter->title, 'parcours' => $track->title]),
            )
            ->setCanonical($url = $this->schema->url('app_exercise', ['_locale' => $track->locale, 'trackId' => $track->id, 'exerciseId' => $exercise->id]))
            ->addStructuredData($this->schema->breadcrumb([
                [$track->title, $this->schema->url('app_track', ['_locale' => $track->locale, 'trackId' => $track->id])],
                [$chapter->title, $this->schema->url('app_chapter_summary', ['_locale' => $track->locale, 'trackId' => $track->id, 'chapterId' => $chapter->id])],
                [$exercise->title, $url],
            ]))
            ->addStructuredData([
                '@type' => 'LearningResource',
                'name' => $exercise->title,
                'description' => $this->seo->getDescription(),
                'url' => $url,
                'learningResourceType' => 'exercise',
                'inLanguage' => $this->seo->getLanguage(),
                'isAccessibleForFree' => $track->isFreeChapter($chapter),
                ...(null === $exercise->duration ? [] : ['timeRequired' => DurationExtension::iso($exercise->duration)]),
                ...([] === $exercise->concepts ? [] : ['teaches' => $exercise->concepts]),
                ...(null === $track->level ? [] : ['educationalLevel' => $this->trans('parcours.niveau.'.$track->level)]),
                'isPartOf' => [
                    '@type' => 'Course',
                    '@id' => $this->schema->url('app_track', ['_locale' => $track->locale, 'trackId' => $track->id]).'#cours',
                    'name' => $track->title,
                    'url' => $this->schema->url('app_track', ['_locale' => $track->locale, 'trackId' => $track->id]),
                ],
                'provider' => $this->schema->organization(),
            ]);
        $this->alternates($track, 'app_exercise', ['trackId' => $track->id, 'exerciseId' => $exercise->id]);
        $this->shareImage($track);
    }

    /** L'image de partage du parcours (clé « image » de track.yaml) sur ses pages ; sans elle, celle de la marque. */
    private function shareImage(Track $track): void
    {
        if (null !== ($url = $this->shareImageUrl($track))) {
            $this->seo->setImage($url, sprintf('%s · %s', $track->title, $this->theme->name()));
        }
    }

    /** L'adresse de l'image du parcours, datée de son fichier : une image remplacée change d'URL (voir TrackController::image). */
    private function shareImageUrl(Track $track): ?string
    {
        return null === $track->image ? null : $this->schema->url('app_track_image', ['trackId' => $track->id, 'v' => (int) filemtime($track->image)]);
    }

    /**
     * Le catalogue (/parcours) : la page qui répond à « apprendre Symfony en ligne », « formation Laravel »… Le titre
     * nomme les frameworks des parcours publiés ; les données structurées listent les cours (ItemList de Course).
     *
     * @param list<Track> $tracks les parcours publiés, sans filtre
     */
    public function catalogue(array $tracks): void
    {
        $frameworks = array_map(fn (Track $track) => $this->framework($track->environment), $tracks)
            |> array_unique(...)
            |> array_values(...);
        $subject = match (\count($frameworks)) {
            0 => $this->trans('catalogue.sujet_par_defaut'),
            1 => $frameworks[0],
            // « Symfony, Laravel et Nuxt », « Symfony, Laravel, and Nuxt » : la ponctuation de chaque langue, par ICU.
            default => new \IntlListFormatter($this->translator->getLocale())->format($frameworks),
        };
        $this->seo
            ->setTitle(
                $this->trans('catalogue.titre_marque', ['sujet' => $subject, 'marque' => $this->theme->name()]),
                $this->trans('catalogue.titre', ['sujet' => $subject]),
                $this->trans('catalogue.titre_court'),
            )
            ->setDescription(
                $this->trans('catalogue.description', ['sujet' => $subject]),
                $this->trans('catalogue.complement'),
            )
            ->setCanonical($url = $this->schema->url('app_tracks'))
            ->addStructuredData([
                '@type' => 'ItemList',
                'name' => $this->trans('catalogue.liste'),
                'url' => $url,
                'numberOfItems' => \count($tracks),
                'itemListElement' => array_map(fn (Track $track, int $index) => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'item' => [
                        '@type' => 'Course',
                        'name' => $track->title,
                        'description' => self::plain($track->description),
                        'url' => $this->schema->url('app_track', ['_locale' => $track->locale, 'trackId' => $track->id]),
                        'provider' => $this->schema->organization(),
                    ],
                ], $tracks, array_keys($tracks)),
            ])
            ->addStructuredData($this->schema->breadcrumb([[$this->trans('catalogue.fil_ariane'), $url]]));
        // Le catalogue existe dans chaque langue, mais ne s'annonce que là où un parcours est traduit (hreflang).
        foreach ($this->locales as $locale) {
            if ($locale !== $this->seo->getLanguage() && [] !== $this->content->tracks($locale)) {
                $this->seo->addAlternate($locale, $this->schema->url('app_tracks', ['_locale' => $locale]));
            }
        }
    }

    /**
     * Les autres langues d'une page de parcours : celles où le parcours est traduit (hreflang).
     *
     * @param array<string, string> $parameters
     */
    private function alternates(Track $track, string $route, array $parameters): void
    {
        foreach ($this->content->trackLocales($track->id) as $locale) {
            if ($locale !== $track->locale) {
                $this->seo->addAlternate($locale, $this->schema->url($route, ['_locale' => $locale] + $parameters));
            }
        }
    }

    /** « Symfony », « Laravel »… d'après l'environnement d'exécution. */
    public function framework(string $environmentId): string
    {
        return $this->environments->frameworkOf($environmentId)->label;
    }

    /**
     * Les titres candidats d'une page de contenu, menés par ses notions, du plus complet au plus court
     * (PageSeo garde le premier qui tient). C'est la notion que l'on cherche dans un moteur, pas « Les prix
     * en pièces d'or » ; le libellé narratif ferme chaque candidat, car il distingue deux pages d'une même notion.
     *
     * @param list<string> $concepts
     *
     * @return list<string>
     */
    private function conceptLed(array $concepts, string $framework, string $label): array
    {
        $titles = [];
        for ($count = \count($concepts); $count > 0; --$count) {
            $titles[] = $this->trans('contenu.notions_framework', ['notions' => implode(', ', \array_slice($concepts, 0, $count)), 'framework' => $framework, 'libelle' => $label]);
        }
        if ([] !== $concepts) {
            // Dernier recours avant d'abandonner la notion : sans le framework. Jamais sans le libellé,
            // qui seul distingue deux pages — un title en double n'aide personne, et le test du sitemap le refuse.
            $titles[] = $this->trans('contenu.notion', ['notion' => $concepts[0], 'libelle' => $label]);
        }

        return $titles;
    }

    /** @param array<string, mixed> $parameters */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, 'seo');
    }

    private static function plain(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
