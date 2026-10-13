<?php

namespace App\Seo\Page;

use App\Content\Article;
use App\Content\Blog;
use App\Theme\Theme;
use App\Seo\PageSeo;
use App\Seo\SchemaOrg;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Le blog : la liste, et un article (BlogPosting). Un article porte ses propres title et description, écrits
 * dans son en-tête : c'est le texte que l'auteur a choisi pour les résultats de recherche.
 */
final readonly class BlogSeo
{
    public function __construct(
        private PageSeo $seo,
        private SchemaOrg $schema,
        private Theme $theme,
        private TranslatorInterface $translator,
        private Blog $blog,
    ) {
    }

    /** @param list<Article> $articles */
    public function index(array $articles): void
    {
        $this->seo
            ->setTitle($this->trans('blog.titre_marque', ['marque' => $this->theme->name()]), $this->trans('blog.titre'))
            ->setDescription(
                $this->trans('blog.description'),
                \count($articles) > 1 ? $this->trans('blog.nombre_articles', ['n' => \count($articles)]) : '',
            )
            ->setCanonical($url = $this->schema->url('app_blog'))
            ->addStructuredData($this->schema->breadcrumb([[$this->trans('blog.fil_ariane'), $url]]));
        foreach ($this->otherLanguages() as $locale) {
            $this->seo->addAlternate($locale, $this->schema->url('app_blog', ['_locale' => $locale]));
        }
    }

    /** @param array<string, Article> $translations le même article dans les autres langues (voir Blog::translations()) */
    public function article(Article $article, array $translations = []): void
    {
        foreach ($translations as $locale => $translation) {
            $this->seo->addAlternate($locale, $this->schema->url('app_article', ['_locale' => $locale, 'slug' => $translation->slug]));
        }
        $this->seo
            ->setTitle(sprintf('%s | %s', $article->title, $this->theme->name()), $article->title)
            ->setDescription($article->description)
            ->setCanonical($url = $this->schema->url('app_article', ['slug' => $article->slug]))
            ->setType('article')
            ->addStructuredData([
                '@type' => 'BlogPosting',
                'headline' => PageSeo::shorten($article->title, 110),
                'description' => $article->description,
                'url' => $url,
                'mainEntityOfPage' => $url,
                'inLanguage' => $this->seo->getLanguage(),
                ...(null === ($image = $this->theme->shareUrl()) ? [] : ['image' => $this->schema->absolute($image)]),
                'datePublished' => $article->published->format('Y-m-d'),
                'dateModified' => $article->modified(),
                'author' => $this->schema->author(),
                'publisher' => [
                    ...$this->schema->organization(),
                    ...(null === ($logo = $this->theme->logoLargeUrl()) ? [] : ['logo' => ['@type' => 'ImageObject', 'url' => $this->schema->absolute($logo)]]),
                ],
            ])
            ->addStructuredData($this->schema->breadcrumb([
                [$this->trans('blog.fil_ariane'), $this->schema->url('app_blog')],
                [$article->title, $url],
            ]));
    }

    /** @return list<string> les autres langues où le blog a des articles parus */
    private function otherLanguages(): array
    {
        $current = $this->seo->getLanguage();

        return array_values(array_filter([Article::LOCALE, ...Article::TRANSLATIONS], fn (string $locale) => $locale !== $current && $this->blog->isOpen($locale)));
    }

    /** @param array<string, mixed> $parameters */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, 'seo');
    }
}
