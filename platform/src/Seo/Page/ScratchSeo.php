<?php

namespace App\Seo\Page;

use App\Scratch\ScratchFramework;
use App\Seo\PageSeo;
use App\Seo\SchemaOrg;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Le bac à sable : une page web gratuite par framework (WebPage), et la foire aux questions de sa page d'accueil
 * (FAQPage). Titre, description et canonique viennent de #[Seo] sur les actions (voir StaticPageSeo).
 *
 * Pas de WebApplication : Google exige une note ou des avis sur toute application (aggregateRating ou review), et
 * le bac à sable n'en a pas. Sans eux, les pages sortent « non valides » dans la Search Console.
 */
final readonly class ScratchSeo
{
    public function __construct(
        private PageSeo $seo,
        private SchemaOrg $schema,
        private TranslatorInterface $translator,
    ) {
    }

    /** @param list<array{question: string, answer: string}> $faq les questions fréquentes de la page */
    public function index(array $faq): void
    {
        $this->seo->addStructuredData(['@graph' => [
            $this->schema->organization(),
            [
                ...$this->page($this->schema->url('app_scratch')),
                // Les bacs à sable de chaque framework, décrits sur leur propre page.
                'hasPart' => array_map(fn (ScratchFramework $framework) => [
                    '@type' => 'WebPage',
                    '@id' => $this->schema->url('app_scratch_'.$framework->value).'#page',
                    'name' => $this->translator->trans('bac_a_sable.projet.titre', ['framework' => $framework->label()], 'apprentissage'),
                    'url' => $this->schema->url('app_scratch_'.$framework->value),
                ], ScratchFramework::cases()),
            ],
            [
                '@type' => 'FAQPage',
                'mainEntity' => array_map(static fn (array $item) => [
                    '@type' => 'Question',
                    'name' => $item['question'],
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['answer']],
                ], $faq),
            ],
        ]]);
    }

    public function project(ScratchFramework $framework): void
    {
        $this->seo->addStructuredData(['@graph' => [
            $this->schema->organization(),
            [
                ...$this->page($this->schema->url('app_scratch_'.$framework->value)),
                'isPartOf' => ['@id' => $this->schema->url('app_scratch').'#page'],
            ],
        ]]);
    }

    /**
     * La page en cours : titre et description sont ceux que #[Seo] a posés (StaticPageSeo passe avant l'action).
     *
     * @return array<string, mixed>
     */
    private function page(string $url): array
    {
        return [
            '@type' => 'WebPage',
            '@id' => $url.'#page',
            'name' => $this->seo->getTitle(),
            'url' => $url,
            ...(null === ($description = $this->seo->getDescription()) ? [] : ['description' => $description]),
            'inLanguage' => $this->seo->getLanguageTag(),
            'isAccessibleForFree' => true,
            'publisher' => ['@id' => $this->schema->site().'/#organisation'],
        ];
    }
}
