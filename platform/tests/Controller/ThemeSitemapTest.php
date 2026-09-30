<?php

namespace App\Tests\Controller;

use App\Tests\DatabaseTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Un thème installé, sur tout le site : chaque page du sitemap se rend avec lui, et chaque fichier qu'il apporte
 * (images, feuilles, scripts, polices) est servi. strict_variables, actif en test, fait échouer la page dont un
 * gabarit du thème lit une variable que le moteur ne lui donne plus.
 *
 * Par défaut, c'est l'exemple livré (examples/themes/atelier-bigorneau) sur le pack de démonstration. Le dépôt qui
 * publie un thème le vérifie contre le moteur avec les mêmes tests, sur ses propres packs :
 *
 *     THEME_UNDER_TEST=<dossier du thème> CONTENT_PACKS_PATHS=<ses packs> php bin/phpunit --filter ThemeSitemapTest
 */
final class ThemeSitemapTest extends WebTestCase
{
    use DatabaseTrait;

    private const array ENV = ['THEMES_DIR', 'THEME'];

    /** Des pages que le sitemap ne liste pas, mais que tout visiteur voit. */
    private const array PAGES = ['/connexion', '/inscription', '/mot-de-passe-oublie'];

    /** @var array<string, string|null> */
    private array $before = [];

    protected function setUp(): void
    {
        $directory = (string) ($_SERVER['THEME_UNDER_TEST'] ?? getenv('THEME_UNDER_TEST')) ?: \dirname(__DIR__, 3).'/examples/themes/atelier-bigorneau';
        $this->assertDirectoryExists($directory, 'THEME_UNDER_TEST ne mène à aucun dossier.');
        $directory = (string) realpath($directory);

        foreach (self::ENV as $name) {
            $this->before[$name] = $_SERVER[$name] ?? null;
        }
        // Le thème est servi comme en production : un dossier de THEMES_DIR, choisi par THEME.
        $_SERVER['THEMES_DIR'] = $_ENV['THEMES_DIR'] = \dirname($directory);
        $_SERVER['THEME'] = $_ENV['THEME'] = basename($directory);
    }

    protected function tearDown(): void
    {
        foreach ($this->before as $name => $value) {
            if (null === $value) {
                unset($_SERVER[$name], $_ENV[$name]);
            } else {
                $_SERVER[$name] = $_ENV[$name] = $value;
            }
        }
        parent::tearDown();
    }

    public function testChaquePageDuSitemapSeRendAvecLeTheme(): void
    {
        $client = $this->client();

        $client->request('GET', '/sitemap.xml');
        $this->assertResponseIsSuccessful();
        preg_match_all('#<loc>([^<]+)</loc>#', (string) $client->getResponse()->getContent(), $locations);
        $this->assertNotEmpty($locations[1], 'Le sitemap ne liste aucune page.');

        $pages = [...array_map(static fn (string $url): string => (string) parse_url(html_entity_decode($url), \PHP_URL_PATH), $locations[1]), ...self::PAGES];
        $this->assertSame([], $this->failures($client, $pages), 'Des pages ne se rendent pas avec le thème.');
    }

    public function testLeThemeSertAussiLesPagesDUnApprenantConnecte(): void
    {
        $client = $this->client();
        $client->loginUser($this->createUser());

        $this->assertSame([], $this->failures($client, ['/', '/parcours', '/pratique']), 'Des pages ne se rendent pas avec le thème.');
    }

    /** Ce que le thème apporte (logo, favicon, feuilles, polices préchargées, scripts) répond, sur l'accueil. */
    public function testLesFichiersDuThemeSontServis(): void
    {
        $client = $this->client();

        $crawler = $client->request('GET', '/');
        $this->assertResponseIsSuccessful();
        $files = array_values(array_unique(array_filter(
            [...$crawler->filter('link[href]')->extract(['href']), ...$crawler->filter('script[src], img[src]')->extract(['src'])],
            static fn (string $url): bool => str_starts_with((string) parse_url($url, \PHP_URL_PATH), '/theme/'),
        )));

        $this->assertSame([], $this->failures($client, $files), 'Des fichiers du thème ne sont pas servis.');
    }

    private function client(): KernelBrowser
    {
        $client = static::createClient();
        // Le noyau reste le même d'une requête à l'autre, comme en mode worker : c'est aussi bien plus rapide.
        $client->disableReboot();
        $this->resetDatabase();

        return $client;
    }

    /**
     * Les adresses qui ne répondent pas 200, avec leur statut et le titre de la page d'erreur.
     *
     * @param list<string> $urls
     *
     * @return array<string, string>
     */
    private function failures(KernelBrowser $client, array $urls): array
    {
        $failures = [];
        foreach ($urls as $url) {
            $client->request('GET', $url);
            $response = $client->getResponse();
            if (200 !== $response->getStatusCode()) {
                preg_match('#<title>(.*?)</title>#s', (string) $response->getContent(), $title);
                $failures[$url] = $response->getStatusCode().' '.trim(html_entity_decode($title[1] ?? ''));
            }
        }

        return $failures;
    }
}
