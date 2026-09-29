<?php

namespace App\Seo\Page;

use App\Content\Chapter;
use App\Content\ContentRepository;
use App\Content\EnvironmentRegistry;
use App\Content\Exercise;
use App\Content\ExerciseStory;
use App\Content\Framework\FrameworkRegistry;
use App\Content\Track;
use App\Instance\Branding;
use App\Payment\TrackOfferFactory;
use App\Seo\PageSeo;
use App\Seo\SchemaOrg;
use App\Seo\TrackSeoText;
use App\Twig\DurationExtension;

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
        private FrameworkRegistry $frameworks,
        private TrackOfferFactory $offers,
        private TrackSeoText $trackText,
        private ContentRepository $content,
        private Branding $branding,
        private ExerciseStory $stories,
    ) {
    }

    public function track(Track $track): void
    {
        $this->seo
            ->setFullTitle($this->trackText->title($track, $this->framework($track->environment)))
            ->setDescription($this->trackText->description($track))
            ->setCanonical($url = $this->schema->url('app_track', ['trackId' => $track->id]));

        // Le prix affiché à un visiteur : prix courant (fondateur compris), en euros TTC.
        $offer = $this->offers->create($track, null);
        $duration = null === ($minutes = $this->content->durationOf($track)) ? null : DurationExtension::iso($minutes);
        $this->shareImage($track);
        // Google exige une image : celle du parcours, sinon celle du partage de la marque, à défaut le logo.
        $image = $this->shareImageUrl($track) ?? $this->branding->shareUrl() ?? $this->branding->logoLargeUrl() ?? $this->branding->logoUrl();
        $this->seo
            ->addStructuredData([
                '@type' => 'Course',
                'name' => $track->title,
                'description' => self::plain($track->description),
                'url' => $url,
                'inLanguage' => 'fr',
                'provider' => $this->schema->organization(),
                ...(null === $image ? [] : ['image' => $this->schema->absolute($image)]),
                // Le résultat enrichi « Cours » de Google demande la charge de travail sur chaque session (courseWorkload) ;
                // timeRequired, au niveau du cours, ne la remplace pas.
                'hasCourseInstance' => [
                    '@type' => 'CourseInstance',
                    'courseMode' => 'Online',
                    'inLanguage' => 'fr',
                    ...(null === $duration ? [] : ['courseWorkload' => $duration]),
                ],
                ...(null === $duration ? [] : ['timeRequired' => $duration]),
                'syllabusSections' => array_map(static fn (Chapter $chapter) => ['@type' => 'Syllabus', 'name' => $chapter->title], $track->chapters),
                'offers' => [
                    '@type' => 'Offer',
                    'category' => $offer->quote->isFree() ? 'Free' : 'Paid',
                    'price' => number_format($offer->quote->price / 100, 2, '.', ''),
                    'priceCurrency' => 'EUR',
                    'url' => $url,
                    'availability' => $offer->quote->isFree() || $offer->paymentsEnabled ? 'https://schema.org/InStock' : 'https://schema.org/PreOrder',
                ],
            ])
            ->addStructuredData($this->schema->breadcrumb([$track->title => $url]));
    }

    /**
     * Le sommaire d'un chapitre : ce qu'il fait apprendre, et combien d'exercices l'y amènent.
     *
     * @param list<string> $concepts les notions de ses exercices, sans doublon
     */
    public function chapter(Track $track, Chapter $chapter, int $number, array $concepts): void
    {
        $framework = $this->framework($chapter->environment ?? $track->environment);
        $count = \count($chapter->exerciseIds);
        $this->seo
            ->setTitle(
                ...$this->conceptLed($concepts, $framework, $chapter->title),
                ...[sprintf('%s – chapitre %d, parcours %s', $chapter->title, $number, $framework), $chapter->title],
            )
            ->setDescription(
                sprintf(
                    'Chapitre %d du parcours %s : %s. %d exercice%s à faire en écrivant du code, corrigé%s par des tests.',
                    $number,
                    $track->title,
                    $concepts ? mb_strtolower(implode(', ', \array_slice($concepts, 0, 4))) : mb_strtolower($chapter->title),
                    $count,
                    $count > 1 ? 's' : '',
                    $count > 1 ? 's' : '',
                ),
            )
            ->setCanonical($url = $this->schema->url('app_chapter_summary', ['trackId' => $track->id, 'chapterId' => $chapter->id]))
            ->addStructuredData($this->schema->breadcrumb([
                $track->title => $this->schema->url('app_track', ['trackId' => $track->id]),
                $chapter->title => $url,
            ]));
        $this->shareImage($track);
    }

    public function exercise(Track $track, Chapter $chapter, Exercise $exercise): void
    {
        $framework = $this->framework($exercise->environment);
        $this->seo
            ->setTitle(
                ...$this->conceptLed($exercise->concepts, $framework, 'exercice : '.$exercise->title),
                ...[sprintf('%s – exercice %s', $exercise->title, $framework), $exercise->title],
            )
            ->setDescription(
                $this->stories->fromInstructions($exercise->instructions),
                sprintf('Un exercice du chapitre « %s », parcours %s.', $chapter->title, $track->title),
            )
            ->setCanonical($url = $this->schema->url('app_exercise', ['trackId' => $track->id, 'exerciseId' => $exercise->id]))
            ->addStructuredData($this->schema->breadcrumb([
                $track->title => $this->schema->url('app_track', ['trackId' => $track->id]),
                $chapter->title => $this->schema->url('app_chapter_summary', ['trackId' => $track->id, 'chapterId' => $chapter->id]),
                $exercise->title => $url,
            ]));
        $this->shareImage($track);
    }

    /** L'image de partage du parcours (clé « image » de track.yaml) sur ses pages ; sans elle, celle de la marque. */
    private function shareImage(Track $track): void
    {
        if (null !== ($url = $this->shareImageUrl($track))) {
            $this->seo->setImage($url, sprintf('%s · %s', $track->title, $this->branding->name()));
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
        $frameworks = array_values(array_unique(array_map(fn (Track $track) => $this->framework($track->environment), $tracks)));
        $subject = match (\count($frameworks)) {
            0 => 'le développement web',
            1 => $frameworks[0],
            default => implode(', ', \array_slice($frameworks, 0, -1)).' et '.end($frameworks),
        };
        $name = $this->branding->name();
        $this->seo
            ->setTitle(
                sprintf('Parcours pour apprendre %s en codant | %s', $subject, $name),
                sprintf('Apprendre %s en codant : les parcours', $subject),
                'Les parcours : apprendre en codant',
            )
            ->setDescription(
                sprintf('Apprenez %s en écrivant du code dans votre navigateur : un vrai projet, un aperçu en direct, des tests qui valident chaque objectif.', $subject),
                'Premier chapitre gratuit avec un compte.',
            )
            ->setCanonical($url = $this->schema->url('app_tracks'))
            ->addStructuredData([
                '@type' => 'ItemList',
                'name' => 'Les parcours',
                'url' => $url,
                'numberOfItems' => \count($tracks),
                'itemListElement' => array_map(fn (Track $track, int $index) => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'item' => [
                        '@type' => 'Course',
                        'name' => $track->title,
                        'description' => self::plain($track->description),
                        'url' => $this->schema->url('app_track', ['trackId' => $track->id]),
                        'provider' => $this->schema->organization(),
                    ],
                ], $tracks, array_keys($tracks)),
            ])
            ->addStructuredData($this->schema->breadcrumb(['Parcours' => $url]));
    }

    /** « Symfony », « Laravel »… d'après l'environnement d'exécution. */
    public function framework(string $environmentId): string
    {
        return $this->environments->has($environmentId)
            ? $this->environments->get($environmentId)->framework->label
            : $this->frameworks->default()->label;
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
            $titles[] = implode(', ', \array_slice($concepts, 0, $count)).' en '.$framework.' – '.$label;
        }
        if ([] !== $concepts) {
            // Dernier recours avant d'abandonner la notion : sans le framework. Jamais sans le libellé,
            // qui seul distingue deux pages — un title en double n'aide personne, et le test du sitemap le refuse.
            $titles[] = $concepts[0].' – '.$label;
        }

        return $titles;
    }

    private static function plain(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
