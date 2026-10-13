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
}
