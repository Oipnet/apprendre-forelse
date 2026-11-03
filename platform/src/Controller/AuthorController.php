<?php

namespace App\Controller;

use App\Content\Blog;
use App\Content\LessonRenderer;
use App\Content\TrackVisibility;
use App\Seo\Page\AuthorSeo;
use App\Theme\Theme;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * La page d'un auteur : la personne du thème (« person » de theme.yaml), sa présentation, ses profils, ses articles et
 * ses parcours. Elle dit à Google qui écrit (données structurées ProfilePage), et sert de vitrine à qui veut travailler
 * avec lui. Un thème sans présentation n'a pas de page : 404.
 */
final class AuthorController extends AbstractController
{
    /** Assez pour montrer la régularité, sans refaire la liste du blog. */
    private const int ARTICLES = 6;

    /**
     * L'adresse nomme l'auteur (/auteurs/arnaud-pointet) : aujourd'hui la seule personne du thème, demain l'un des
     * auteurs du contenu, sans que les liens déjà partagés ne cassent.
     */
    #[Route(['fr' => '/auteurs/{slug}', 'en' => '/en/authors/{slug}'], name: 'app_author', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['GET'])]
    public function show(string $slug, Theme $theme, Blog $blog, TrackVisibility $tracks, LessonRenderer $markdown, AuthorSeo $seo): Response
    {
        $author = $theme->author($slug);
        if (null === $author || !$author['hasPage'] || !$theme->speaks($tracks->locale())) {
            throw $this->createNotFoundException();
        }
        $seo->page($author);
        // Ses articles : ceux qui le nomment, ou, pour l'auteur par défaut, ceux qui ne nomment personne (ou un inconnu).
        $known = array_column($theme->authors(), 'id');
        $articles = array_values(array_filter($blog->articles(), static function ($article) use ($blog, $author, $known): bool {
            $id = $blog->authorOf($article);

            return $author['isDefault'] ? null === $id || !\in_array($id, $known, true) || $id === $author['id'] : $id === $author['id'];
        }));

        return $this->render('author/show.html.twig', [
            'person' => $author,
            // La présentation longue ; la courte (« bio ») est déjà sous son nom.
            'about' => isset($author['about']) ? $markdown->toHtml($author['about']) : null,
            'articles' => \array_slice($articles, 0, self::ARTICLES),
            'more' => \count($articles) > self::ARTICLES,
            // Les parcours sont ceux de l'auteur par défaut, celui que nomment leurs données structurées.
            'tracks' => $author['isDefault'] ? array_values(array_filter($tracks->tracksHere(), static fn ($track) => !$track->isRestricted() && $track->locale === $tracks->locale())) : [],
        ]);
    }
}
