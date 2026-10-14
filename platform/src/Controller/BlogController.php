<?php

namespace App\Controller;

use App\Content\Article;
use App\Content\Blog;
use App\Content\LessonRenderer;
use App\Seo\Page\BlogSeo;
use App\Seo\SchemaOrg;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Le blog : la liste des articles parus, et un article (voir App\Content\Article). En français sous /blog, en anglais
 * sous /en/blog pour les articles traduits : la langue de la route (_locale) est celle de la page et de ses textes.
 */
final class BlogController extends AbstractController
{
    /** Sans article paru, pas de blog : une page vide ne servirait personne, et ne doit pas s'indexer. */
    #[Route(['fr' => '/blog', 'en' => '/en/blog'], name: 'app_blog', methods: ['GET'])]
    public function index(Blog $blog, BlogSeo $seo): Response
    {
        if (!$blog->isOpen()) {
            throw $this->createNotFoundException();
        }
        $articles = $blog->articles();
        $seo->index($articles);

        return $this->render('blog/index.html.twig', ['articles' => $articles]);
    }

    /**
     * Le flux RSS des articles parus, texte complet compris : de quoi les suivre dans un lecteur, ou les reprendre
     * ailleurs (dev.to, Hashnode importent un flux et renvoient vers l'article d'origine). Les liens du texte, relatifs
     * au site, deviennent absolus : lus hors du site, ils mèneraient nulle part. Ils portent aussi des paramètres utm :
     * les copies masquent souvent leur referrer, et sans eux leurs visites se fondraient dans le trafic direct. Le lien de
     * l'article lui-même (<link>, <guid>) reste nu, puisque les copies en font leur URL canonique.
     */
    #[Route(['fr' => '/blog/rss.xml', 'en' => '/en/blog/rss.xml'], name: 'app_blog_feed', methods: ['GET'])]
    public function feed(Blog $blog, LessonRenderer $markdown, SchemaOrg $schema): Response
    {
        if (!$blog->isOpen()) {
            throw $this->createNotFoundException();
        }
        $site = $schema->site();
        $items = array_map(fn (Article $article): array => [
            'article' => $article,
            'url' => $schema->url('app_article', ['slug' => $article->slug]),
            'html' => $this->tagged(
                preg_replace('/\b(href|src)="\/(?!\/)/', '$1="'.$site.'/', $markdown->toHtmlUnderTitle($article->markdown)),
                $site,
                $article->slug,
            ),
        ], \array_slice($blog->articles(), 0, 20));

        $response = $this->render('blog/feed.xml.twig', ['items' => $items, 'self' => $schema->url('app_blog_feed'), 'blog_url' => $schema->url('app_blog')]);
        $response->headers->set('Content-Type', 'application/rss+xml; charset=UTF-8');

        return $response->setPublic()->setMaxAge(3600);
    }

    /** Ajoute utm_source=rss aux liens du texte qui mènent au site, avant l'ancre éventuelle. */
    private function tagged(string $html, string $site, string $slug): string
    {
        $utm = http_build_query(['utm_source' => 'rss', 'utm_medium' => 'feed', 'utm_campaign' => $slug], arg_separator: '&amp;');

        return preg_replace_callback(
            '/\bhref="('.preg_quote($site, '/').'(?:[\/?][^"#]*)?)(#[^"]*)?"/',
            fn (array $m): string => 'href="'.$m[1].(str_contains($m[1], '?') ? '&amp;' : '?').$utm.($m[2] ?? '').'"',
            $html,
        );
    }

    #[Route(['fr' => '/blog/{slug}', 'en' => '/en/blog/{slug}'], name: 'app_article', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['GET'])]
    public function show(string $slug, Blog $blog, BlogSeo $seo, LessonRenderer $markdown): Response
    {
        $article = $blog->find($slug) ?? throw $this->createNotFoundException();
        $translations = $blog->translations($article);
        $seo->article($article, $translations);

        return $this->render('blog/show.html.twig', [
            'article' => $article,
            // Le titre de tête du Markdown, s'il y en a un, répète celui de la page : il disparaît.
            'html' => $markdown->toHtmlUnderTitle($article->markdown),
            'scheduled' => $blog->isScheduled($article),
            'translations' => $translations,
            ...$blog->neighbours($article),
        ]);
    }
}
