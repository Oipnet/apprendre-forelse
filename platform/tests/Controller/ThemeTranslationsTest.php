<?php

namespace App\Tests\Controller;

use App\Tests\DatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Un gabarit de thème lit ses textes dans translations/theme.<langue>.yaml : l'exemple livré, sur l'accueil. */
final class ThemeTranslationsTest extends WebTestCase
{
    use DatabaseTrait;

    private const array ENV = ['THEMES_DIR', 'THEME'];

    /** @var array<string, string|null> */
    private array $before = [];

    protected function setUp(): void
    {
        foreach (self::ENV as $name) {
            $this->before[$name] = $_SERVER[$name] ?? null;
        }
        $_SERVER['THEMES_DIR'] = $_ENV['THEMES_DIR'] = \dirname(__DIR__, 3).'/examples/themes';
        $_SERVER['THEME'] = $_ENV['THEME'] = 'atelier-bigorneau';
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

    public function testLesTextesDuThemeViennentDeSesFichiers(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $crawler = $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertStringEndsWith('· à marée haute comme à marée basse', $crawler->filter('.bigorneau-hero__eyebrow')->text());
        $this->assertSame('Voir les parcours', $crawler->filter('.home-cta a')->last()->text());
    }

    /** /en : les textes du thème sous « locales: en: » et ceux de theme.en.yaml, le reste en anglais par le moteur. */
    public function testLAccueilAnglaisPrendLesTextesAnglaisDuTheme(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $crawler = $client->request('GET', '/en');

        $this->assertResponseIsSuccessful();
        $this->assertSame('en', $crawler->filter('html')->attr('lang'));
        $this->assertStringEndsWith('· at high tide and low tide', $crawler->filter('.bigorneau-hero__eyebrow')->text());
        $this->assertSame('Every track has its common thread. The first one: a fishing port.', $crawler->filter('.home-showcase h2')->text());
        $this->assertSame('Learn to code by coding in the browser, with the workshop\'s tools.', $crawler->filter('.site-footer .footer-brand p')->first()->text());
        $this->assertSame('http://localhost/', $crawler->filter('.footer-language a')->attr('href'), 'Le pied de page mène à la version française.');
    }

    /** Un thème sans textes anglais n'a pas d'accueil anglais : il mélangerait les deux langues. */
    public function testSansTextesAnglaisPasDAccueilAnglais(): void
    {
        $directory = sys_get_temp_dir().'/theme-'.bin2hex(random_bytes(6));
        mkdir($directory.'/sans-anglais', recursive: true);
        file_put_contents($directory.'/sans-anglais/theme.yaml', "name: Sans anglais\n");
        $_SERVER['THEMES_DIR'] = $_ENV['THEMES_DIR'] = $directory;
        $_SERVER['THEME'] = $_ENV['THEME'] = 'sans-anglais';
        try {
            $client = static::createClient();
            $this->resetDatabase();

            $crawler = $client->request('GET', '/');
            $this->assertResponseIsSuccessful();
            $this->assertCount(0, $crawler->filter('link[hreflang]'), 'Pas d\'autre langue annoncée.');
            $this->assertCount(0, $crawler->filter('.footer-language'));

            $client->request('GET', '/en');
            $this->assertResponseStatusCodeSame(404);

            $client->request('GET', '/sitemap.xml');
            $this->assertStringNotContainsString('http://localhost/en<', (string) $client->getResponse()->getContent());
        } finally {
            (new \Symfony\Component\Filesystem\Filesystem())->remove($directory);
        }
    }
}
