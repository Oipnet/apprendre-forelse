<?php

namespace App\Controller;

use App\Content\Blog;
use App\Content\LessonRenderer;
use App\Seo\Page\BlogSeo;
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
