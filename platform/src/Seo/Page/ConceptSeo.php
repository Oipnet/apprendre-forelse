<?php

namespace App\Seo\Page;

use App\Content\ConceptIndex;
use App\Content\Exercise;
use App\Content\ExerciseStory;
use App\Content\Practice;
use App\Theme\Theme;
use App\Seo\PageSeo;
use App\Seo\SchemaOrg;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * L'index des notions, et la page d'une notion : le mot que l'on cherche, et ce qui le fait pratiquer. Dans la langue
 * de la page (/notions, /en/concepts).
 */
final readonly class ConceptSeo
{
    public function __construct(
        private PageSeo $seo,
        private SchemaOrg $schema,
        private Theme $theme,
        private ExerciseStory $stories,
        private TranslatorInterface $translator,
        private ConceptIndex $concepts,
    ) {
    }

    /**
     * L'index des notions.
     *
     * @param list<array{name: string, slug: string, exercises: int}> $concepts
     */
    public function index(array $concepts): void
    {
        $url = $this->schema->url('app_concepts');
        $this->seo
            ->setTitle($this->trans('notions.titre_marque', ['marque' => $this->theme->name()]), $this->trans('notions.titre'))
            ->setDescription($this->trans('notions.description', ['n' => \count($concepts)]))
            ->setCanonical($url)
            ->addStructuredData($this->schema->breadcrumb([[$this->trans('notions.fil_ariane'), $url]]));
        // L'index existe dans chaque langue qui a des notions (hreflang).
        foreach ([Exercise::LOCALE, ...Exercise::TRANSLATIONS] as $locale) {
            if ($locale !== $this->seo->getLanguage() && [] !== $this->concepts->all($locale)) {
                $this->seo->addAlternate($locale, $this->schema->url('app_concepts', ['_locale' => $locale]));
            }
        }
    }

    /**
     * Une notion : c'est le mot que l'on cherche dans un moteur, et la page qui rassemble ce qui le pratique. La
     * description reprend l'intro écrite quand il y en a une.
     *
     * La même notion dans une autre langue, quand ConceptIndex sait la reconnaître, est annoncée (hreflang).
     *
     * @param array{name: string, slug: string, exercises: list<array<string, mixed>>, practices: list<Practice>, intro?: string|null, title?: string|null, translations?: array<string, string>} $concept
     */
    public function concept(array $concept): void
    {
        $count = \count($concept['exercises']) + \count($concept['practices']);
        $names = ['notion' => $concept['name'], 'n' => $count];
        $this->seo
            ->setTitle(...(null !== ($title = $concept['title'] ?? null)
                // Écrit par l'auteur : rendu tel quel, avec la marque s'il y a la place.
                ? [sprintf('%s | %s', $title, $this->theme->name()), $title]
                : [
                    $this->trans('notion.titre_marque', $names + ['marque' => $this->theme->name()]),
                    $this->trans('notion.titre', $names),
                    $this->trans('notion.titre_court', $names),
                    $concept['name'],
                ]))
            ->setDescription(
                null !== ($concept['intro'] ?? null) ? $this->stories->firstParagraph($concept['intro']) : $this->trans('notion.description', $names),
                $this->trans('notion.complement', $names),
            )
            ->setCanonical($url = $this->schema->url('app_concept', ['slug' => $concept['slug']]))
            ->addStructuredData($this->schema->breadcrumb([
                [$this->trans('notions.fil_ariane'), $this->schema->url('app_concepts')],
                [$concept['name'], $url],
            ]));
        foreach ($concept['translations'] ?? [] as $locale => $slug) {
            $this->seo->addAlternate($locale, $this->schema->url('app_concept', ['_locale' => $locale, 'slug' => $slug]));
        }
    }

    /** @param array<string, mixed> $parameters */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, 'seo');
    }
}
