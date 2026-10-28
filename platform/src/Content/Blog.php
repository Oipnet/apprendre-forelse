<?php

namespace App\Content;

use App\Entity\User;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Le blog : les articles des packs (voir Article). La liste ne montre que les articles parus ; un article
 * programmé ou en préparation se relit à son adresse, pour les seuls administrateurs, comme la Pratique.
 *
 * Une instance dont aucun pack n'a d'article n'a pas de blog : ni lien dans le pied de page, ni entrée dans le sitemap.
 *
 * Le blog est servi en français (/blog) et dans la langue de ses traductions (/en/blog) : sans langue précisée,
 * chaque méthode répond pour celle de la page demandée. Une langue sans article paru n'a pas de blog.
 */
final readonly class Blog
{
    public function __construct(
        private ContentRepository $content,
        private PublishedContent $published,
        private Security $security,
        private ClockInterface $clock,
        private RequestStack $requests,
    ) {
    }

    /** @return list<Article> les articles parus, du plus récent au plus ancien */
    public function articles(?string $locale = null): array
    {
        return $this->published->articles($locale ?? $this->locale());
    }

    /**
     * Les sujets des articles parus (clé `tags`), du plus fourni au moins fourni : les filtres de la liste. La clé est
     * le filtre de l'adresse (?sujet=securite), la valeur le libellé tel que l'écrit le premier article qui le porte.
     *
     * @return array<string, array{label: string, count: int}>
     */
    public function topics(?string $locale = null): array
    {
        $topics = [];
        foreach ($this->articles($locale) as $article) {
            foreach ($article->tags as $tag) {
                $topics[self::topic($tag)] ??= ['label' => $tag, 'count' => 0];
                ++$topics[self::topic($tag)]['count'];
            }
        }
        // uasort est stable : à nombre égal, l'ordre d'apparition (le sujet le plus récent d'abord).
        uasort($topics, static fn (array $a, array $b) => $b['count'] <=> $a['count']);

        return $topics;
    }

    /**
     * Les articles parus d'un sujet (voir topics()), du plus récent au plus ancien.
     *
     * @return list<Article>
     */
    public function articlesAbout(string $topic, ?string $locale = null): array
    {
        return array_values(array_filter(
            $this->articles($locale),
            static fn (Article $a) => \in_array($topic, array_map(self::topic(...), $a->tags), true),
        ));
    }

    /**
     * Des articles rangés par mois de parution, dans l'ordre reçu : les intertitres de la liste.
     *
     * @param list<Article> $articles
     *
     * @return list<array{month: \DateTimeImmutable, articles: list<Article>}>
     */
    public static function byMonth(array $articles): array
    {
        $months = [];
        foreach ($articles as $article) {
            $month = $article->published->format('Y-m');
            $months[$month] ??= ['month' => $article->published->modify('first day of this month')->setTime(0, 0), 'articles' => []];
            $months[$month]['articles'][] = $article;
        }

        return array_values($months);
    }

    /** Le filtre d'un sujet dans l'adresse : « Sécurité » donne « securite ». */
    public static function topic(string $tag): string
    {
        return (new AsciiSlugger('fr'))->slug($tag)->lower()->toString();
    }

    /** Au moins un article paru : le blog a une page, et un lien. */
    public function isOpen(?string $locale = null): bool
    {
        return [] !== $this->articles($locale);
    }

    /** Un article lisible par l'utilisateur courant, ou null. */
    public function find(string $slug, ?string $locale = null): ?Article
    {
        $article = $this->content->findArticle($slug, $locale ?? $this->locale());
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
        $articles = $this->articles($article->locale);
        $position = array_search($article->slug, array_map(static fn (Article $a) => $a->slug, $articles), true);
        if (false === $position) {
            return ['previous' => null, 'next' => null];
        }

        // Du plus récent au plus ancien : le précédent (plus ancien) suit dans la liste.
        return ['previous' => $articles[$position + 1] ?? null, 'next' => $articles[$position - 1] ?? null];
    }

    /**
     * Le même article, paru, dans les autres langues : de quoi relier les pages entre elles (hreflang) et proposer
     * l'autre version au lecteur. Une traduction programmée n'apparaît qu'à sa date.
     *
     * @return array<string, Article> par langue
     */
    public function translations(Article $article): array
    {
        $now = $this->clock->now();

        return array_filter($this->content->translationsOf($article), static fn (Article $a) => !$a->isRestricted() && !$a->isScheduled($now));
    }

    /** L'exercice de Pratique dont part l'article : le sien, ou celui de l'article français qu'il traduit. */
    public function practiceOf(Article $article): ?string
    {
        if (null !== $article->practice || null === $article->translationOf) {
            return $article->practice;
        }

        return $this->content->findArticle($article->translationOf)?->practice;
    }

    /** La langue de la page demandée, si le blog la parle ; le français sinon. */
    public function locale(): string
    {
        $locale = $this->requests->getCurrentRequest()?->getLocale();

        return \in_array($locale, Article::TRANSLATIONS, true) ? $locale : Article::LOCALE;
    }
}
