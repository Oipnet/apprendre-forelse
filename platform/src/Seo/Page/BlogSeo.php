<?php

namespace App\Seo\Page;

use App\Content\Article;
use App\Instance\Branding;
use App\Seo\PageSeo;
use App\Seo\SchemaOrg;

/**
 * Le blog : la liste, et un article (BlogPosting). Un article porte ses propres title et description, écrits
 * dans son en-tête : c'est le texte que l'auteur a choisi pour les résultats de recherche.
 */
final readonly class BlogSeo
{
    public function __construct(
        private PageSeo $seo,
        private SchemaOrg $schema,
        private Branding $branding,
    ) {
    }

    /** @param list<Article> $articles */
    public function index(array $articles): void
    {
        $this->seo
            ->setTitle('Le blog | '.$this->branding->name(), 'Le blog')
            ->setDescription(
                'Les coulisses de la plateforme : comment un framework tourne dans le navigateur, comment un exercice se construit et se corrige.',
                \count($articles) > 1 ? sprintf('%d articles.', \count($articles)) : '',
            )
            ->setCanonical($url = $this->schema->url('app_blog'))
            ->addStructuredData($this->schema->breadcrumb([['Le blog', $url]]));
    }

    public function article(Article $article): void
    {
        $this->seo
            ->setTitle(sprintf('%s | %s', $article->title, $this->branding->name()), $article->title)
            ->setDescription($article->description)
            ->setCanonical($url = $this->schema->url('app_article', ['slug' => $article->slug]))
            ->setType('article')
            ->addStructuredData([
                '@type' => 'BlogPosting',
                'headline' => PageSeo::shorten($article->title, 110),
                'description' => $article->description,
                'url' => $url,
                'mainEntityOfPage' => $url,
                'inLanguage' => 'fr',
                ...(null === ($image = $this->branding->shareUrl()) ? [] : ['image' => $this->schema->absolute($image)]),
                'datePublished' => $article->published->format('Y-m-d'),
                'dateModified' => $article->modified(),
                'author' => $this->schema->author(),
                'publisher' => [
                    ...$this->schema->organization(),
                    ...(null === ($logo = $this->branding->logoLargeUrl()) ? [] : ['logo' => ['@type' => 'ImageObject', 'url' => $this->schema->absolute($logo)]]),
                ],
            ])
            ->addStructuredData($this->schema->breadcrumb([
                ['Le blog', $this->schema->url('app_blog')],
                [$article->title, $url],
            ]));
    }
}
