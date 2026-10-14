<?php

namespace App\Seo\Page;

use App\Content\ContentDates;
use App\Content\ContentRepository;
use App\Content\Exercise;
use App\Content\ExerciseStory;
use App\Content\Framework\FrameworkRegistry;
use App\Content\Practice;
use App\Theme\Theme;
use App\Seo\PageSeo;
use App\Seo\SchemaOrg;
use App\Share\PracticeShare;
use App\Share\ShareImageRenderer;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * La Pratique : la liste, les nouveautés d'une version de framework et un exercice (Article).
 */
final readonly class PracticeSeo
{
    public function __construct(
        private PageSeo $seo,
        private SchemaOrg $schema,
        private FrameworkRegistry $frameworks,
        private Theme $theme,
        private ExerciseStory $stories,
        private ContentDates $dates,
        private PracticeShare $share,
        private TranslatorInterface $translator,
        private ContentRepository $content,
    ) {
    }

    /** @param list<Practice> $practices ceux que la liste montre sans filtre */
    public function index(array $practices): void
    {
        $frameworks = array_values(array_unique(array_map(fn (Practice $p) => $this->frameworks->labelOf($p->framework), $practices)));
        $subject = match (\count($frameworks)) {
            0 => $this->trans('pratique.sujet_par_defaut'),
            1 => $frameworks[0],
            default => $this->trans('commun.liste', ['debut' => implode(', ', \array_slice($frameworks, 0, -1)), 'dernier' => end($frameworks)]),
        };

        $this->seo
            ->setTitle(
                $this->trans('pratique.titre_marque', ['sujet' => $subject, 'marque' => $this->theme->name()]),
                $this->trans('pratique.titre', ['sujet' => $subject]),
                $this->trans('pratique.titre_court'),
            )
            ->setDescription($this->trans('pratique.description'))
            ->setCanonical($url = $this->schema->url('app_practice'))
            ->addStructuredData($this->schema->breadcrumb([[$this->trans('pratique.fil_ariane'), $url]]));
        // La liste existe dans chaque langue où un exercice est traduit (hreflang).
        foreach ([Exercise::LOCALE, ...Exercise::TRANSLATIONS] as $locale) {
            if ($locale !== $this->seo->getLanguage() && [] !== $this->content->practices($locale)) {
                $this->seo->addAlternate($locale, $this->schema->url('app_practice', ['_locale' => $locale]));
            }
        }
    }

    /**
     * Une version de framework : ce qu'elle apporte, en exercices. Le titre mène par « Nouveautés Symfony 8.2 »,
     * qui est la requête, et la page ne prétend pas les lister toutes (voir PracticeVersionIndex).
     *
     * La description reprend l'intro écrite pour cette version quand il y en a une ; sinon elle nomme ce que
     * la page fait travailler, pour que deux pages ne se ressemblent pas.
     *
     * @param array{label: string, slug: string, practices: list<Practice>, intro: string|null, concepts: list<string>} $version
     */
    public function version(array $version): void
    {
        $count = \count($version['practices']);
        $concepts = \array_slice($version['concepts'], 0, 3);
        $names = ['version' => $version['label'], 'n' => $count];
        $this->seo
            ->setTitle(
                $this->trans('pratique.version.titre_marque', $names + ['marque' => $this->theme->name()]),
                $this->trans('pratique.version.titre', $names),
                $this->trans('pratique.version.nouveautes', $names),
            )
            ->setDescription(
                null !== $version['intro']
                    ? $this->stories->firstParagraph($version['intro'])
                    : ([] === $concepts
                        ? $this->trans('pratique.version.description_sans_notions', $names)
                        : $this->trans('pratique.version.description', $names + ['notions' => implode(', ', $concepts)])),
                $this->trans('pratique.version.complement', $names),
            )
            ->setCanonical($url = $this->schema->url('app_practice_version', ['slug' => $version['slug']]))
            ->addStructuredData($this->schema->breadcrumb([
                [$this->trans('pratique.fil_ariane'), $this->schema->url('app_practice')],
                [$this->trans('pratique.version.nouveautes', $names), $url],
            ]));
    }

    public function practice(Practice $practice): void
    {
        $framework = trim($this->frameworks->labelOf($practice->framework).' '.$practice->version);
        $feature = self::lowerFirst($practice->exercise->title);
        // L'image de partage de l'exercice, dessinée par le moteur : son titre, sur l'habillage du thème.
        $image = $this->share->imageUrl($practice);

        $this->seo
            ->setTitle(
                $this->trans('pratique.exercice.titre', ['framework' => $framework, 'fonctionnalite' => $feature]),
                $this->trans('pratique.exercice.titre_court', ['framework' => $framework, 'fonctionnalite' => $feature]),
            )
            ->setDescription($practice->summary, $this->trans('pratique.exercice.complement', ['framework' => $framework]))
            ->setCanonical($url = $this->schema->url('app_exercise_pratique', ['exerciseId' => $practice->exercise->id]))
            ->setType('article')
            ->setImage($image, $this->share->card($practice)->alt($this->translator), ShareImageRenderer::WIDTH, ShareImageRenderer::HEIGHT)
            ->addStructuredData([
                '@type' => 'Article',
                'headline' => PageSeo::shorten($practice->exercise->title, 110),
                'description' => $practice->summary,
                'url' => $url,
                'mainEntityOfPage' => $url,
                'inLanguage' => $this->seo->getLanguage(),
                'image' => $image,
                'datePublished' => $practice->published->format('Y-m-d'),
                'dateModified' => $this->dates->practiceModified($practice),
                'author' => $this->schema->author(),
                'publisher' => [
                    ...$this->schema->organization(),
                    ...(null === ($logo = $this->theme->logoLargeUrl()) ? [] : ['logo' => ['@type' => 'ImageObject', 'url' => $this->schema->absolute($logo)]]),
                ],
                'keywords' => implode(', ', [$this->frameworks->labelOf($practice->framework), ...$practice->exercise->concepts]),
            ])
            ->addStructuredData($this->schema->breadcrumb([
                [$this->trans('pratique.fil_ariane'), $this->schema->url('app_practice')],
                [$practice->exercise->title, $url],
            ]));
        // Le même exercice dans ses autres langues (hreflang).
        foreach ($this->content->practiceLocales($practice->exercise->id) as $locale) {
            if ($locale !== $practice->locale()) {
                $this->seo->addAlternate($locale, $this->schema->url('app_exercise_pratique', ['_locale' => $locale, 'exerciseId' => $practice->exercise->id]));
            }
        }
    }

    /** @param array<string, mixed> $parameters */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, 'seo');
    }

    /** « Lire un en-tête » → « lire un en-tête », mais « PHP 8.4 » reste tel quel. */
    private static function lowerFirst(string $text): string
    {
        return 1 === preg_match('/^\p{Lu}\p{Ll}/u', $text) ? mb_strtolower(mb_substr($text, 0, 1)).mb_substr($text, 1) : $text;
    }
}
