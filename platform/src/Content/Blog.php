<?php

namespace App\Content;

use App\Entity\User;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Le blog : les articles des packs (voir Article). La liste ne montre que les articles parus ; un article
 * programmé ou en préparation se relit à son adresse, pour les seuls administrateurs, comme la Pratique.
 *
 * Une instance dont aucun pack n'a d'article n'a pas de blog : ni lien dans le pied de page, ni entrée dans le sitemap.
 */
final readonly class Blog
{
    public function __construct(
        private ContentRepository $content,
        private PublishedContent $published,
        private Security $security,
        private ClockInterface $clock,
    ) {
    }

    /** @return list<Article> les articles parus, du plus récent au plus ancien */
    public function articles(): array
    {
        return $this->published->articles();
    }

    /** Au moins un article paru : le blog a une page, et un lien. */
    public function isOpen(): bool
    {
        return [] !== $this->articles();
    }

    /** Un article lisible par l'utilisateur courant, ou null. */
    public function find(string $slug): ?Article
    {
        $article = $this->content->findArticle($slug);
        if (null === $article) {
            return null;
        }

        return (!$article->isRestricted() && !$this->isScheduled($article)) || $this->security->isGranted(User::ROLE_ADMIN) ? $article : null;
    }

    public function isScheduled(Article $article): bool
    {
        return $article->isScheduled($this->clock->now());
    }

    /**
     * Les articles parus autour de celui-ci : le plus récent avant lui, le plus ancien après. Un article non paru
     * n'en a pas — il n'est encore dans aucune suite.
     *
     * @return array{previous: Article|null, next: Article|null}
     */
    public function neighbours(Article $article): array
    {
        $articles = $this->articles();
        $position = array_search($article->slug, array_map(static fn (Article $a) => $a->slug, $articles), true);
        if (false === $position) {
            return ['previous' => null, 'next' => null];
        }

        // Du plus récent au plus ancien : le précédent (plus ancien) suit dans la liste.
        return ['previous' => $articles[$position + 1] ?? null, 'next' => $articles[$position - 1] ?? null];
    }
}
