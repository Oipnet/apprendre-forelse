<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Tests\DatabaseTrait;
use App\Tests\PacksTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Le blog : les articles des packs. Pack de test « pratique » : deux articles parus (le plus récent révisé depuis),
 * un programmé, un en préparation.
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

    public function testLaListeMontreLesArticlesParusDuPlusRecentAuPlusAncien(): void
    {
        $crawler = $this->client->request('GET', '/blog');

        $this->assertResponseIsSuccessful();
        $this->assertSame(
            ['Symfony dans le navigateur, sans serveur', 'Des tests PHPUnit en direct'],
            $crawler->filter('.blog-list h2')->extract(['_text']),
            'Ni l\'article programmé, ni celui en préparation.',
        );
        $this->assertSame('/blog/symfony-dans-le-navigateur', $crawler->filter('.blog-list h2 a')->first()->attr('href'));
        $this->assertStringContainsString('20 septembre 2026', $crawler->filter('.blog-list .meta')->first()->text());
        $this->assertSelectorExists('.site-footer a[href="/blog"]', 'Le pied de page mène au blog.');
        $this->assertSelectorExists('header nav a[href="/blog"]', 'Le menu principal aussi.');
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
        $this->assertSame('Symfony dans le navigateur, sans serveur | Forelse', $crawler->filter('title')->text());
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
        $this->assertCount(2, $crawler->filter('.blog-list li'), 'La liste reste celle des visiteurs.');
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

    public function testSansArticleIlNyAPasDeBlog(): void
    {
        $this->usePacks(__DIR__.'/../../../examples/packs');
        $this->client->request('GET', '/');
        $this->assertSelectorNotExists('.site-footer a[href="/blog"]');
        $this->assertSelectorNotExists('header nav a[href="/blog"]');

        $this->client->request('GET', '/blog');
        $this->assertResponseStatusCodeSame(404);
        $this->assertResponseHeaderSame('X-Robots-Tag', 'noindex');

        $this->client->request('GET', '/sitemap.xml');
        $this->assertStringNotContainsString('/blog', (string) $this->client->getResponse()->getContent());
    }
}
