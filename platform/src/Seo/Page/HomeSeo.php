<?php

namespace App\Seo\Page;

use App\Theme\Theme;
use App\Seo\PageSeo;
use App\Seo\SchemaOrg;

/**
 * L'accueil : l'éditeur, le site, l'auteur et la foire aux questions (Organization, WebSite, Person, FAQPage).
 */
final readonly class HomeSeo
{
    public function __construct(
        private PageSeo $seo,
        private SchemaOrg $schema,
        private Theme $theme,
    ) {
    }

    /** @param list<array{question: string, answer: string}> $faq les questions fréquentes de la page */
    public function write(array $faq): void
    {
        $home = $this->schema->url('app_home');
        $this->seo
            ->setTitle('Apprendre à développer en codant dans le navigateur | '.$this->theme->name(), 'Apprendre à développer en codant dans le navigateur')
            ->setDescription('Apprenez à développer en codant dans votre navigateur, sans vidéo ni installation : un vrai projet, des tests automatiques, le premier chapitre gratuit.')
            ->setCanonical($home)
            ->addStructuredData(['@graph' => [
                [
                    ...$this->schema->organization(),
                    ...(null === ($logo = $this->theme->logoLargeUrl()) ? [] : ['logo' => $this->schema->absolute($logo)]),
                    ...('' === $this->theme->url() ? [] : ['sameAs' => [$this->theme->url()]]),
                ],
                [
                    '@type' => 'WebSite',
                    '@id' => $home.'#site',
                    'name' => $this->theme->name(),
                    'alternateName' => $this->theme->signature(),
                    'url' => $home,
                    'inLanguage' => 'fr-FR',
                    'publisher' => ['@id' => $home.'#organisation'],
                ],
                $this->schema->author(),
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
}
