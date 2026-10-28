<?php

namespace App\Seo\Page;

use App\Scratch\ScratchFramework;
use App\Seo\PageSeo;
use App\Seo\SchemaOrg;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Le bac à sable : une application web gratuite par framework (WebApplication), et la foire aux questions de sa page
 * d'accueil (FAQPage). Titre, description et canonique viennent de #[Seo] sur les actions (voir StaticPageSeo).
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
            ...array_map($this->application(...), ScratchFramework::cases()),
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
        $this->seo->addStructuredData(['@graph' => [$this->schema->organization(), $this->application($framework)]]);
    }

    /** @return array<string, mixed> */
    private function application(ScratchFramework $framework): array
    {
        return [
            '@type' => 'WebApplication',
            'name' => $this->translator->trans('bac_a_sable.projet.titre', ['framework' => $framework->label()], 'apprentissage'),
            'url' => $this->schema->url('app_scratch_'.$framework->value),
            'description' => $this->translator->trans('bac_a_sable.'.$framework->value.'.description', [], 'seo'),
            'applicationCategory' => 'DeveloperApplication',
            'operatingSystem' => 'Any',
            'browserRequirements' => 'Requires JavaScript and WebAssembly',
            'inLanguage' => $this->seo->getLanguageTag(),
            'isAccessibleForFree' => true,
            'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'EUR'],
            'publisher' => ['@id' => $this->schema->site().'/#organisation'],
        ];
    }
}
