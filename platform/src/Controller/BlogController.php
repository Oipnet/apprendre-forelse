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

/** Le blog : la liste des articles parus, et un article (voir App\Content\Article). */
final class BlogController extends AbstractController
{
    /** Sans article paru, pas de blog : une page vide ne servirait personne, et ne doit pas s'indexer. */
    #[Route('/blog', name: 'app_blog', methods: ['GET'])]
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
     * au site, deviennent absolus : lus hors du site, ils mèneraient nulle part.
     */
    #[Route('/blog/rss.xml', name: 'app_blog_feed', methods: ['GET'])]
    public function feed(Blog $blog, LessonRenderer $markdown, SchemaOrg $schema): Response
    {
        if (!$blog->isOpen()) {
            throw $this->createNotFoundException();
        }
        $site = rtrim($schema->url('app_home'), '/');
        $items = array_map(fn (Article $article): array => [
            'article' => $article,
            'url' => $schema->url('app_article', ['slug' => $article->slug]),
            'html' => preg_replace('/\b(href|src)="\/(?!\/)/', '$1="'.$site.'/', $markdown->toHtmlUnderTitle($article->markdown)),
        ], \array_slice($blog->articles(), 0, 20));

        $response = $this->render('blog/feed.xml.twig', ['items' => $items, 'self' => $schema->url('app_blog_feed'), 'blog_url' => $schema->url('app_blog')]);
        $response->headers->set('Content-Type', 'application/rss+xml; charset=UTF-8');

        return $response->setPublic()->setMaxAge(3600);
    }

    #[Route('/blog/{slug}', name: 'app_article', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['GET'])]
    public function show(string $slug, Blog $blog, BlogSeo $seo, LessonRenderer $markdown): Response
    {
        $article = $blog->find($slug) ?? throw $this->createNotFoundException();
        $seo->article($article);

        return $this->render('blog/show.html.twig', [
            'article' => $article,
            // Le titre de tête du Markdown, s'il y en a un, répète celui de la page : il disparaît.
            'html' => $markdown->toHtmlUnderTitle($article->markdown),
            'scheduled' => $blog->isScheduled($article),
            ...$blog->neighbours($article),
        ]);
    }
}
