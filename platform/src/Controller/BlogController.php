<?php

namespace App\Controller;

use App\Content\Article;
use App\Content\ArticleOutline;
use App\Content\Blog;
use App\Content\LessonRenderer;
use App\Content\PracticeTrack;
use App\Content\PracticeVisibility;
use App\Content\TrackVisibility;
use App\Search\RelatedContent;
use App\Search\SearchDocument;
use App\Seo\Page\BlogSeo;
use App\Seo\SchemaOrg;
use App\Share\ArticleShare;
use App\Share\ShareImage;
use App\Theme\Theme;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Le blog : la liste des articles parus, et un article (voir App\Content\Article). En français sous /blog, en anglais
 * sous /en/blog pour les articles traduits : la langue de la route (_locale) est celle de la page et de ses textes.
 */
final class BlogController extends AbstractController
{
    /**
     * Sans article paru, pas de blog : une page vide ne servirait personne, et ne doit pas s'indexer.
     *
     * Le plus récent est mis en avant, les autres sont rangés par mois. ?sujet= filtre la liste sur un sujet (voir
     * Blog::topics()) : la page filtrée se suit mais ne s'indexe pas (SearchIndexing), et un sujet inconnu répond 404.
     */
    #[Route(['fr' => '/blog', 'en' => '/en/blog'], name: 'app_blog', methods: ['GET'])]
    public function index(Blog $blog, BlogSeo $seo, #[MapQueryParameter] ?string $sujet = null): Response
    {
        if (!$blog->isOpen()) {
            throw $this->createNotFoundException();
        }
        $topics = $blog->topics();
        if (null !== $sujet && !isset($topics[$sujet])) {
            throw $this->createNotFoundException();
        }
        $articles = $blog->articles();
        $seo->index($articles);
        $listed = null === $sujet ? \array_slice($articles, 1) : $blog->articlesAbout($sujet);

        return $this->render('blog/index.html.twig', [
            'articles' => $articles,
            'featured' => null === $sujet ? $articles[0] : null,
            'months' => Blog::byMonth($listed),
            'topics' => $topics,
            'topic' => $sujet,
            'count' => null === $sujet ? \count($articles) : \count($listed),
            'languages' => array_values(array_filter(
                [Article::LOCALE, ...Article::TRANSLATIONS],
                static fn (string $locale) => $locale !== $blog->locale() && $blog->isOpen($locale),
            )),
        ]);
    }

    /**
     * Le flux RSS des articles parus, texte complet compris : de quoi les suivre dans un lecteur, ou les reprendre
     * ailleurs (dev.to, Hashnode importent un flux et renvoient vers l'article d'origine). Les liens du texte, relatifs
     * au site, deviennent absolus : lus hors du site, ils mèneraient nulle part. Ils portent aussi des paramètres utm :
     * les copies masquent souvent leur referrer, et sans eux leurs visites se fondraient dans le trafic direct. Le lien de
     * l'article lui-même (<link>, <guid>) reste nu, puisque les copies en font leur URL canonique. Les sujets de
     * l'article deviennent des <category> (dev.to en fait ses tags), son image une enclosure (voir ArticleShare).
     */
    #[Route(['fr' => '/blog/rss.xml', 'en' => '/en/blog/rss.xml'], name: 'app_blog_feed', methods: ['GET'])]
    public function feed(Blog $blog, LessonRenderer $markdown, SchemaOrg $schema, ArticleShare $share): Response
    {
        if (!$blog->isOpen()) {
            throw $this->createNotFoundException();
        }
        $site = $schema->site();
        $items = array_map(fn (Article $article): array => [
            'article' => $article,
            'url' => $schema->url('app_article', ['slug' => $article->slug]),
            // L'image de l'article, en enclosure et en media:content : les lecteurs de flux en font la vignette.
            'image' => ['url' => $share->imageUrl($article), 'length' => $share->imageSize($article)],
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

    /**
     * L'image d'un article, dessinée par le moteur au premier appel (voir ArticleShare). L'adresse que donnent la page
     * et le flux porte sa version : sous cette adresse, l'image ne change plus.
     */
    #[Route(['fr' => '/blog/{slug}/image-de-partage', 'en' => '/en/blog/{slug}/share-image'], name: 'app_article_image', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['GET'])]
    public function image(string $slug, Request $request, Blog $blog, ArticleShare $share, ShareImage $images): BinaryFileResponse
    {
        $article = $blog->find($slug) ?? throw $this->createNotFoundException();

        return $images->response($share->card($article), $request);
    }

    #[Route(['fr' => '/blog/{slug}', 'en' => '/en/blog/{slug}'], name: 'app_article', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['GET'])]
    public function show(string $slug, Blog $blog, BlogSeo $seo, LessonRenderer $markdown, PracticeVisibility $practices, PracticeTrack $practiceTracks, TrackVisibility $tracks, RelatedContent $related, Theme $theme): Response
    {
        $article = $blog->find($slug) ?? throw $this->createNotFoundException();
        $translations = $blog->translations($article);
        $seo->article($article, $translations);
        $practice = null !== ($id = $blog->practiceOf($article)) ? $practices->find($id) : null;
        $courseId = $blog->courseOf($article) ?? (null !== $practice ? $practiceTracks->of($practice)?->id : null);
        $course = null !== $courseId ? $tracks->findHere($courseId) : null;

        return $this->render('blog/show.html.twig', [
            'article' => $article,
            // Le titre de tête du Markdown, s'il y en a un, répète celui de la page : il disparaît. Les intertitres
            // reçoivent une ancre, et le sommaire en tête d'un article assez long.
            ...$this->outline($markdown->toHtmlUnderTitle($article->markdown)),
            'scheduled' => $blog->isScheduled($article),
            'translations' => $translations,
            // L'exercice dont part l'article, s'il est visible dans cette langue : la page invite à le faire. Sans lui,
            // elle invite aux exercices de Pratique en général. Un lecteur arrivé d'ailleurs repart sinon après l'article.
            'practice' => $practice,
            // Le parcours qui prolonge l'article (clé `course`, ou celui du framework de l'exercice), s'il est visible et
            // traduit dans la langue de la page : l'article attire, l'exercice fait essayer, le parcours est ce qui se vend.
            'course' => $course,
            // Qui a écrit l'article : l'encart « Écrit par » le présente (un identifiant inconnu retombe sur l'auteur par défaut).
            'author' => $theme->author($blog->authorOf($article)) ?? $theme->author(),
            // Ça pourrait vous intéresser : les contenus proches (voir App\Search\RelatedContent), sans l'exercice ni le parcours déjà proposés.
            'related' => $related->forArticle($article, array_values(array_filter([
                null !== $practice ? SearchDocument::idOf(SearchDocument::PRACTICE, $practice->exercise->id) : null,
                null !== $course ? SearchDocument::idOf(SearchDocument::TRACK, $course->id) : null,
            ]))),
            ...$blog->neighbours($article),
        ]);
    }

    /** @return array{html: string, sommaire: list<array{id: string, title: string}>} */
    private function outline(string $html): array
    {
        $outline = ArticleOutline::of($html);

        return ['html' => $outline['html'], 'sommaire' => $outline['sections']];
    }
}
