<?php

namespace App\Tests\Controller;

use App\Content\Blog;
use App\Content\ContentRepository;
use App\Entity\User;
use App\Tests\DatabaseTrait;
use App\Tests\PacksTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Le blog : les articles des packs. Pack de test « pratique » : deux articles parus (le plus récent révisé depuis),
 * un programmé, un en préparation ; en anglais, la traduction du plus récent et celle de l'article programmé.
 */
final class BlogTest extends WebTestCase
{
    use DatabaseTrait;
    use PacksTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->usePacks(__DIR__.'/../../../examples/packs', __DIR__.'/../Fixtures/packs/pratique');
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    protected function tearDown(): void
    {
        $this->restorePacks();
        parent::tearDown();
    }

    private function loginAdmin(): void
    {
        $admin = $this->createUser('admin@example.test', 'Admin');
        $admin->setRoles([User::ROLE_ADMIN]);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->client->loginUser($admin);
    }

    public function testLaListeMetEnAvantLePlusRecentPuisRangeLesAutresParMois(): void
    {
        $crawler = $this->client->request('GET', '/blog');

        $this->assertResponseIsSuccessful();
        $this->assertSame('Symfony dans le navigateur, sans serveur', $crawler->filter('.blog-featured h2')->text(), 'Le plus récent, à la une.');
        $this->assertSame('/blog/symfony-dans-le-navigateur', $crawler->filter('.blog-featured h2 a')->attr('href'));
        $this->assertStringContainsString('20 septembre 2026', $crawler->filter('.blog-featured .meta')->text());
        $this->assertSame(
            ['Des tests PHPUnit en direct'],
            $crawler->filter('.blog-list h4')->extract(['_text']),
            'Les autres, sans l\'article à la une, ni l\'article programmé, ni celui en préparation.',
        );
        $this->assertSame('septembre 2026', $crawler->filter('.blog-month h3')->text(), 'Sous l\'intertitre de leur mois.');
        $this->assertStringContainsString('5 septembre 2026', $crawler->filter('.blog-list .meta')->first()->text());
        $this->assertSame('2 articles', $crawler->filter('#blog-tous + span')->text());
        $this->assertSelectorExists('.site-footer a[href="/blog"]', 'Le pied de page mène au blog.');
        $this->assertSelectorExists('header nav a[href="/blog"]', 'Le menu principal aussi.');
        $this->assertSelectorExists('.blog-feed a[href="/blog/rss.xml"]', 'La liste propose de s\'abonner au flux.');
        $this->assertSame('/en/blog', $crawler->filter('.blog-language a')->attr('href'), 'Et le blog dans l\'autre langue.');
    }

    public function testLaListeSeFiltreParSujet(): void
    {
        $crawler = $this->client->request('GET', '/blog');
        $this->assertSame(
            ['Tous 2', 'Coulisses 2', 'Symfony 1'],
            array_map(static fn (string $t) => preg_replace('/\s+/u', ' ', trim($t)), $crawler->filter('.blog-topics a')->extract(['_text'])),
            'Les sujets, du plus fourni au moins fourni.',
        );
        $this->assertSame('/blog?sujet=symfony', $crawler->filter('.blog-featured .blog-tags a')->first()->attr('href'));

        $crawler = $this->client->request('GET', '/blog?sujet=coulisses');
        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('.blog-featured'), 'Filtrée, la liste n\'a pas d\'article à la une.');
        $this->assertSame(
            ['Symfony dans le navigateur, sans serveur', 'Des tests PHPUnit en direct'],
            $crawler->filter('.blog-list h4')->extract(['_text']),
        );
        $this->assertStringStartsWith('Coulisses', trim($crawler->filter('.blog-topics a[aria-current="page"]')->text()));
        $this->assertSame('Coulisses', $crawler->filter('#blog-tous')->text());
        $this->assertSame('noindex, follow', $this->client->getResponse()->headers->get('X-Robots-Tag'), 'La page filtrée se suit, mais ne s\'indexe pas.');
        $this->assertSame('http://localhost/blog', $crawler->filter('link[rel="canonical"]')->attr('href'));

        $crawler = $this->client->request('GET', '/blog?sujet=symfony');
        $this->assertSame(['Symfony dans le navigateur, sans serveur'], $crawler->filter('.blog-list h4')->extract(['_text']));
        $this->assertSame('1 article', $crawler->filter('#blog-tous + span')->text());

        $this->client->request('GET', '/blog?sujet=inconnu');
        $this->assertResponseStatusCodeSame(404, 'Un sujet qu\'aucun article ne porte n\'a pas de page.');
    }

    public function testLAuteurDuThemeSePresente(): void
    {
        $crawler = $this->client->request('GET', '/blog');

        $person = static::getContainer()->get(\App\Theme\Theme::class)->person();
        if (null === $person) {
            $this->assertCount(0, $crawler->filter('.blog-author'), 'Un thème sans personne n\'a pas d\'encart auteur.');

            return;
        }
        $this->assertStringContainsString($person['name'], $crawler->filter('.blog-author')->text());
        $this->assertSame('/contact', $crawler->filter('.blog-author a')->attr('href'));
    }

    public function testUnArticle(): void
    {
        $crawler = $this->client->request('GET', '/blog/symfony-dans-le-navigateur');

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('h1'), 'Le titre de tête du Markdown ne se répète pas sous celui de la page.');
        $this->assertSame('Symfony dans le navigateur, sans serveur', $crawler->filter('h1')->text());
        $this->assertSame('Le Service Worker', $crawler->filter('.lesson h2')->text());
        $this->assertCount(1, $crawler->filter('.lesson pre'), 'Le bloc de code est rendu.');
        $this->assertStringNotContainsString('<script>alert', (string) $this->client->getResponse()->getContent(), 'Le HTML brut du pack est supprimé.');

        $meta = preg_replace('/\s+/u', ' ', $crawler->filter('article header .meta')->text());
        $this->assertStringContainsString('20 septembre 2026', $meta);
        $this->assertStringContainsString('mis à jour le 25 septembre 2026', $meta);
        $this->assertSame('2026-09-20', $crawler->filter('article header time')->first()->attr('datetime'));

        $this->assertSame('article', $crawler->filter('meta[property="og:type"]')->attr('content'));
        $this->assertSame('Symfony dans le navigateur, sans serveur | default', $crawler->filter('title')->text());
        $this->assertStringStartsWith('Comment un vrai projet Symfony tourne', (string) $crawler->filter('meta[name="description"]')->attr('content'));
        $this->assertSame('/blog/les-tests-en-direct', $crawler->filter('.blog-nav a')->attr('href'), 'Le plus récent mène au précédent.');
    }

    public function testLesDonneesStructureesDecriventUnBlogPosting(): void
    {
        $crawler = $this->client->request('GET', '/blog/symfony-dans-le-navigateur');

        $types = [];
        foreach ($crawler->filter('script[type="application/ld+json"]') as $script) {
            $data = json_decode($script->textContent, true, flags: \JSON_THROW_ON_ERROR);
            foreach ($data['@graph'] ?? [$data] as $object) {
                $types[$object['@type']] = $object;
            }
        }

        $this->assertArrayHasKey('BlogPosting', $types);
        $this->assertSame('2026-09-20', $types['BlogPosting']['datePublished']);
        $this->assertSame('2026-09-25', $types['BlogPosting']['dateModified']);
        $this->assertSame('http://localhost/blog/symfony-dans-le-navigateur', $types['BlogPosting']['url']);
        $this->assertSame(['Accueil', 'Le blog', 'Symfony dans le navigateur, sans serveur'], array_column($types['BreadcrumbList']['itemListElement'], 'name'));
    }

    public function testUnArticleInviteAFaireLExerciceDontIlPart(): void
    {
        $crawler = $this->client->request('GET', '/blog/symfony-dans-le-navigateur');

        $this->assertResponseIsSuccessful();
        $this->assertSame('/pratique/nouveaute-recente', $crawler->filter('article header .blog-cta-top a')->attr('href'), 'En tête, pour qui veut essayer avant de lire.');
        $this->assertSame('Une nouveauté récente →', $crawler->filter('article header .blog-cta-top a')->text());
        $cta = $crawler->filter('aside.blog-cta');
        $this->assertSame('/pratique/nouveaute-recente', $cta->filter('a.btn')->attr('href'), 'En fin d\'article, là où le lecteur repartirait.');
        $this->assertStringContainsString('La fonctionnalité de la dernière version.', $cta->text(), 'Le résumé de l\'exercice.');
        $this->assertSame('article-exercice', $cta->filter('a.btn')->attr('data-umami-event'), 'Le clic se mesure.');
    }

    public function testSansExerciceLArticleInviteALaPratique(): void
    {
        $crawler = $this->client->request('GET', '/blog/les-tests-en-direct');

        $this->assertCount(0, $crawler->filter('.blog-cta-top'));
        $this->assertSame('/pratique', $crawler->filter('aside.blog-cta a.btn')->attr('href'));
    }

    public function testUneTraductionReprendLExerciceDeLArticleFrancais(): void
    {
        $blog = static::getContainer()->get(Blog::class);
        $translation = static::getContainer()->get(ContentRepository::class)->findArticle('symfony-in-the-browser', 'en');
        $this->assertNotNull($translation);
        $this->assertSame('nouveaute-recente', $blog->practiceOf($translation));

        // L'exercice n'est pas traduit : la page anglaise invite aux exercices de Pratique en anglais.
        $crawler = $this->client->request('GET', '/en/blog/symfony-in-the-browser');
        $this->assertCount(0, $crawler->filter('.blog-cta-top'));
        $this->assertSame('/en/practice', $crawler->filter('aside.blog-cta a.btn')->attr('href'));
        $this->assertStringContainsString('Browse the Practice exercises', $crawler->filter('aside.blog-cta')->text());
    }

    public function testUnArticleNonParuNExistePasPourLesVisiteurs(): void
    {
        foreach (['/blog/article-programme', '/blog/article-en-preparation', '/blog/inconnu'] as $path) {
            $this->client->request('GET', $path);
            $this->assertResponseStatusCodeSame(404, $path);
        }
    }

    public function testUnAdministrateurRelitUnArticleNonParu(): void
    {
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/blog/article-programme');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.blog-notice', 'paraîtra le 1 janvier 2099');
        $this->assertCount(0, $crawler->filter('.blog-nav'), 'Un article non paru n\'est dans aucune suite.');

        $this->client->request('GET', '/blog/article-en-preparation');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.blog-notice', 'En préparation');

        $crawler = $this->client->request('GET', '/blog');
        $this->assertCount(1, $crawler->filter('.blog-featured'), 'La liste reste celle des visiteurs.');
        $this->assertCount(1, $crawler->filter('.blog-list li'));
    }

    public function testLeSitemapListeLesArticlesParus(): void
    {
        $this->client->request('GET', '/sitemap.xml');
        $xml = new \SimpleXMLElement((string) $this->client->getResponse()->getContent());
        $lastmods = [];
        foreach ($xml->url as $url) {
            $lastmods[(string) $url->loc] = (string) $url->lastmod;
        }

        $this->assertSame('2026-09-25', $lastmods['http://localhost/blog'] ?? null, 'La liste change avec l\'article le plus récemment révisé.');
        $this->assertSame('2026-09-25', $lastmods['http://localhost/blog/symfony-dans-le-navigateur'] ?? null);
        $this->assertSame('2026-09-05', $lastmods['http://localhost/blog/les-tests-en-direct'] ?? null);
        $this->assertArrayNotHasKey('http://localhost/blog/article-programme', $lastmods);
        $this->assertArrayNotHasKey('http://localhost/blog/article-en-preparation', $lastmods);
    }

    public function testLeFluxRssDonneLesArticlesParusEnEntier(): void
    {
        $crawler = $this->client->request('GET', '/blog');
        $this->assertSame('http://localhost/blog/rss.xml', $crawler->filter('link[rel="alternate"][type="application/rss+xml"]')->attr('href'), 'Les pages annoncent le flux.');

        $this->client->request('GET', '/blog/rss.xml');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/rss+xml; charset=UTF-8');
        $xml = new \SimpleXMLElement((string) $this->client->getResponse()->getContent());
        $items = $xml->channel->item;

        $this->assertCount(2, $items, 'Ni l\'article programmé, ni celui en préparation.');
        $this->assertSame('Symfony dans le navigateur, sans serveur', (string) $items[0]->title);
        $this->assertSame('http://localhost/blog/symfony-dans-le-navigateur', (string) $items[0]->link);
        $this->assertSame('http://localhost/blog/symfony-dans-le-navigateur', (string) $items[0]->guid);
        $this->assertSame('2026-09-20', (new \DateTimeImmutable((string) $items[0]->pubDate))->format('Y-m-d'));
        $this->assertStringStartsWith('Comment un vrai projet Symfony tourne', (string) $items[0]->description);

        $html = (string) $items[0]->children('http://purl.org/rss/1.0/modules/content/')->encoded;
        $this->assertStringContainsString('<h2', $html, 'Le texte complet, pas seulement le chapeau.');
        $this->assertStringContainsString('href="http://localhost/pratique?utm_source=rss&amp;utm_medium=feed&amp;utm_campaign=symfony-dans-le-navigateur"', $html, 'Les liens du site deviennent absolus, et marqués pour la mesure d\'audience.');
        $this->assertStringNotContainsString('<h1', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
    }

    public function testLeBlogEnAnglaisNeMontreQueLesTraductionsParues(): void
    {
        $crawler = $this->client->request('GET', '/en/blog');

        $this->assertResponseIsSuccessful();
        $this->assertSame('en', $crawler->filter('html')->attr('lang'));
        $this->assertSame('Symfony in the browser, without a server', $crawler->filter('.blog-featured h2')->text());
        $this->assertCount(0, $crawler->filter('.blog-list li'), 'Pas la traduction programmée.');
        $this->assertSame('/en/blog/symfony-in-the-browser', $crawler->filter('.blog-featured h2 a')->attr('href'));
        $this->assertStringContainsString('September 20, 2026', $crawler->filter('.blog-featured .meta')->text());
        $this->assertCount(0, $crawler->filter('.blog-topics'), 'Une traduction sans sujets : pas de filtres.');
        $this->assertSame('/blog', $crawler->filter('.blog-language a')->attr('href'));
        $this->assertSame('Lire le blog en français', $crawler->filter('.blog-language a')->text());
        $this->assertSame('Blog', $crawler->filter('h1')->text());
        $this->assertSelectorExists('header nav a[href="/en/blog"]', 'Le menu mène au blog de la langue de la page.');
        $this->assertSame('http://localhost/en/blog/rss.xml', $crawler->filter('link[rel="alternate"][type="application/rss+xml"]')->attr('href'));
        $this->assertSame('http://localhost/blog', $crawler->filter('link[hreflang="fr"]')->attr('href'));
        $this->assertSame('http://localhost/en/blog', $crawler->filter('link[hreflang="en"]')->attr('href'));
        $this->assertNull($this->client->getResponse()->headers->get('X-Robots-Tag'), 'Le blog anglais s\'indexe.');
    }

    public function testUnArticleEtSaTraductionSeNommentLUnLAutre(): void
    {
        $crawler = $this->client->request('GET', '/en/blog/symfony-in-the-browser');

        $this->assertResponseIsSuccessful();
        $this->assertSame('Symfony in the browser, without a server', $crawler->filter('h1')->text());
        $this->assertStringContainsString('September 20, 2026', $crawler->filter('article header .meta')->text());
        $this->assertStringContainsString('1 min read', $crawler->filter('article header .meta')->text());
        $this->assertSame('en_US', $crawler->filter('meta[property="og:locale"]')->attr('content'));
        $this->assertSame('http://localhost/en/blog/symfony-in-the-browser', $crawler->filter('link[rel="canonical"]')->attr('href'));
        $alternates = $crawler->filter('link[rel="alternate"][hreflang]')->extract(['hreflang', 'href']);
        $this->assertSame([
            ['en', 'http://localhost/en/blog/symfony-in-the-browser'],
            ['fr', 'http://localhost/blog/symfony-dans-le-navigateur'],
            ['x-default', 'http://localhost/blog/symfony-dans-le-navigateur'],
        ], $alternates);
        $this->assertSame('/blog/symfony-dans-le-navigateur', $crawler->filter('.blog-translation a')->attr('href'));
        $this->assertSame('Lire cet article en français', $crawler->filter('.blog-translation a')->text());
        $this->assertSelectorTextContains('.breadcrumb', 'Home');

        $crawler = $this->client->request('GET', '/blog/symfony-dans-le-navigateur');
        $this->assertSame('fr', $crawler->filter('html')->attr('lang'));
        $this->assertSame('http://localhost/en/blog/symfony-in-the-browser', $crawler->filter('link[hreflang="en"]')->attr('href'));
        $this->assertSame('/en/blog/symfony-in-the-browser', $crawler->filter('.blog-translation a')->attr('href'));
        $this->assertSame('Read this article in English', $crawler->filter('.blog-translation a')->text());

        $crawler = $this->client->request('GET', '/blog/les-tests-en-direct');
        $this->assertCount(0, $crawler->filter('link[hreflang]'), 'Un article sans traduction ne nomme aucune autre langue.');
        $this->assertCount(0, $crawler->filter('.blog-translation'));
    }

    public function testUneTraductionProgrammeeNExistePasEncore(): void
    {
        $this->client->request('GET', '/en/blog/a-scheduled-article');
        $this->assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/en/blog/symfony-dans-le-navigateur');
        $this->assertResponseStatusCodeSame(404, 'Un article français n\'a pas d\'adresse anglaise.');
        $this->client->request('GET', '/blog/symfony-in-the-browser');
        $this->assertResponseStatusCodeSame(404, 'Ni une traduction d\'adresse française.');

        $this->loginAdmin();
        $crawler = $this->client->request('GET', '/en/blog/a-scheduled-article');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.blog-notice', 'will be published on January 1, 2099');
        $this->assertCount(0, $crawler->filter('.blog-translation'), 'L\'original n\'a pas paru : pas de lien vers lui.');
    }

    public function testLeSitemapEtLeFluxAnglais(): void
    {
        $this->client->request('GET', '/sitemap.xml');
        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('<loc>http://localhost/en/blog</loc>', $content);
        $this->assertStringContainsString('<loc>http://localhost/en/blog/symfony-in-the-browser</loc>', $content);
        $this->assertStringNotContainsString('a-scheduled-article', $content);

        $this->client->request('GET', '/en/blog/rss.xml');
        $this->assertResponseIsSuccessful();
        $xml = new \SimpleXMLElement((string) $this->client->getResponse()->getContent());
        $this->assertSame('en', (string) $xml->channel->language);
        $this->assertSame('http://localhost/en/blog', (string) $xml->channel->link);
        $this->assertCount(1, $xml->channel->item);
        $this->assertSame('http://localhost/en/blog/symfony-in-the-browser', (string) $xml->channel->item[0]->link);
    }

    public function testSansArticleIlNyAPasDeBlog(): void
    {
        $this->usePacks(__DIR__.'/../../../examples/packs');
        $this->client->request('GET', '/');
        $this->assertSelectorNotExists('.site-footer a[href="/blog"]');
        $this->assertSelectorNotExists('header nav a[href="/blog"]');

        $this->client->request('GET', '/blog');
        $this->assertResponseStatusCodeSame(404);
        $this->assertResponseHeaderSame('X-Robots-Tag', 'noindex');

        $this->client->request('GET', '/blog/rss.xml');
        $this->assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/sitemap.xml');
        $this->assertStringNotContainsString('/blog', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/en/blog');
        $this->assertResponseStatusCodeSame(404);
    }
}
