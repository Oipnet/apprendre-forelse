<?php

namespace App\Share;

use App\Content\Article;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * L'image d'un article du blog (« Blog · Sécurité », le titre, le site) : celle de son aperçu sur les réseaux, de
 * ses données structurées et de son entrée dans le flux RSS, que reprennent les sites qui republient le blog.
 * Le même dessin que les exercices de la Pratique : un article n'a rien à fournir pour être illustré.
 */
final readonly class ArticleShare
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private ShareImage $images,
        private TranslatorInterface $translator,
        private SiteHost $site,
    ) {
    }

    /** Le surtitre nomme le premier sujet de l'article, dans sa langue ; « Blog » seul quand il n'en a pas. */
    public function card(Article $article): ShareCard
    {
        $eyebrow = [] === $article->tags
            ? $this->translator->trans('share.carte.blog', locale: $article->locale)
            : $this->translator->trans('share.carte.blog_sujet', ['sujet' => $article->tags[0]], locale: $article->locale);

        return new ShareCard($eyebrow, $article->title, $this->site->name());
    }

    /** L'adresse absolue de l'image, versionnée : elle change avec ce qu'elle écrit. */
    public function imageUrl(Article $article): string
    {
        return $this->urls->generate('app_article_image', [
            '_locale' => $article->locale,
            'slug' => $article->slug,
            'v' => $this->images->version($this->card($article)),
        ], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /** Le poids du fichier, en octets, dessiné s'il ne l'est pas encore : l'enclosure du flux RSS le demande. */
    public function imageSize(Article $article): int
    {
        return (int) filesize($this->images->path($this->card($article)));
    }
}
