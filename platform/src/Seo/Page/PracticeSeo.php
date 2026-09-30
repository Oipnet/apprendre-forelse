<?php

namespace App\Seo\Page;

use App\Content\ContentDates;
use App\Content\ExerciseStory;
use App\Content\Framework\FrameworkRegistry;
use App\Content\Practice;
use App\Theme\Theme;
use App\Seo\PageSeo;
use App\Seo\SchemaOrg;

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
    ) {
    }

    /** @param list<Practice> $practices ceux que la liste montre sans filtre */
    public function index(array $practices): void
    {
        $frameworks = array_values(array_unique(array_map(fn (Practice $p) => $this->frameworks->labelOf($p->framework), $practices)));
        $subject = match (\count($frameworks)) {
            0 => 'des frameworks',
            1 => $frameworks[0],
            default => implode(', ', \array_slice($frameworks, 0, -1)).' et '.end($frameworks),
        };

        $this->seo
            ->setTitle(
                sprintf('Nouveautés %s en exercices courts | %s', $subject, $this->theme->name()),
                sprintf('Nouveautés %s en exercices courts', $subject),
                'Nouveautés des frameworks en exercices courts',
            )
            ->setDescription('Des exercices courts, à part des parcours : un code écrit à l\'ancienne à réécrire avec la nouveauté du framework, directement dans le navigateur.')
            ->setCanonical($url = $this->schema->url('app_practice'))
            ->addStructuredData($this->schema->breadcrumb([['Pratique', $url]]));
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
        $this->seo
            ->setTitle(
                sprintf('Nouveautés %s en exercices | %s', $version['label'], $this->theme->name()),
                sprintf('Nouveautés %s en exercices', $version['label']),
                sprintf('Nouveautés %s', $version['label']),
            )
            ->setDescription(
                null !== $version['intro']
                    ? $this->stories->firstParagraph($version['intro'])
                    : sprintf(
                        [] === $concepts
                            ? '%d exercices courts sur les nouveautés de %s%s : un code écrit à l\'ancienne, à réécrire avec la fonctionnalité.'
                            : '%d exercices courts sur les nouveautés de %s, autour de %s. À écrire dans le navigateur, corrigés par des tests.',
                        $count,
                        $version['label'],
                        [] === $concepts ? '' : implode(', ', $concepts),
                    ),
                sprintf('Les nouveautés de %s en %d exercices courts, à faire dans le navigateur.', $version['label'], $count),
            )
            ->setCanonical($url = $this->schema->url('app_practice_version', ['slug' => $version['slug']]))
            ->addStructuredData($this->schema->breadcrumb([
                ['Pratique', $this->schema->url('app_practice')],
                ['Nouveautés '.$version['label'], $url],
            ]));
    }

    public function practice(Practice $practice): void
    {
        $framework = trim($this->frameworks->labelOf($practice->framework).' '.$practice->version);
        $feature = self::lowerFirst($practice->exercise->title);

        $this->seo
            ->setTitle(
                sprintf('%s : %s – exercice', $framework, $feature),
                sprintf('%s : %s', $framework, $feature),
            )
            ->setDescription($practice->summary, sprintf('Un exercice court de la Pratique %s, à faire dans le navigateur.', $framework))
            ->setCanonical($url = $this->schema->url('app_exercise_pratique', ['exerciseId' => $practice->exercise->id]))
            ->setType('article')
            ->addStructuredData([
                '@type' => 'Article',
                'headline' => PageSeo::shorten($practice->exercise->title, 110),
                'description' => $practice->summary,
                'url' => $url,
                'mainEntityOfPage' => $url,
                'inLanguage' => 'fr',
                ...(null === ($image = $this->theme->shareUrl()) ? [] : ['image' => $this->schema->absolute($image)]),
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
                ['Pratique', $this->schema->url('app_practice')],
                [$practice->exercise->title, $url],
            ]));
    }

    /** « Lire un en-tête » → « lire un en-tête », mais « PHP 8.4 » reste tel quel. */
    private static function lowerFirst(string $text): string
    {
        return 1 === preg_match('/^\p{Lu}\p{Ll}/u', $text) ? mb_strtolower(mb_substr($text, 0, 1)).mb_substr($text, 1) : $text;
    }
}
