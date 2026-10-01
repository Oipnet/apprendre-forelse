<?php

namespace App\Tests\Controller;

use App\Instance\SelfHostingPage;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

/** La page « Auto-hébergement » n'existe que si le thème actif la rédige (self_hosting/index.html.twig). */
final class SelfHostingTest extends WebTestCase
{
    private ?string $tmp = null;
    private ?string $before = null;

    protected function setUp(): void
    {
        $this->before = $_SERVER['BRANDING_DIR'] ?? null;
    }

    protected function tearDown(): void
    {
        // BRANDING_DIR vient de .env (vide) : on le remet tel quel, sans quoi les tests suivants ne le trouvent plus.
        if (null === $this->before) {
            unset($_SERVER['BRANDING_DIR'], $_ENV['BRANDING_DIR']);
        } else {
            $_SERVER['BRANDING_DIR'] = $_ENV['BRANDING_DIR'] = $this->before;
        }
        if (null !== $this->tmp) {
            (new Filesystem())->remove($this->tmp);
        }
        parent::tearDown();
    }

    public function testSansThemeQuiLaRedigeLaPageNExistePas(): void
    {
        $client = static::createClient();

        $client->request('GET', '/auto-hebergement');
        $this->assertResponseStatusCodeSame(404);

        $client->request('GET', '/');
        $this->assertSelectorNotExists('.site-footer a[href="/auto-hebergement"]');
        $this->assertSelectorExists('.site-footer a[href="'.SelfHostingPage::REPOSITORY.'"]', 'Le code du moteur reste lié (AGPL).');
        $client->request('GET', '/sitemap.xml');
        $this->assertStringNotContainsString('/auto-hebergement', (string) $client->getResponse()->getContent());
    }

    public function testUnThemeQuiLaRedigeLaRendLieeEtReferencee(): void
    {
        $this->tmp = sys_get_temp_dir().'/auto-hebergement-'.bin2hex(random_bytes(6));
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->tmp.'/theme.yaml', "name: Atelier Bigorneau\n");
        $filesystem->dumpFile($this->tmp.'/templates/'.SelfHostingPage::TEMPLATE, <<<'TWIG'
            {% extends 'base.html.twig' %}
            {% block body %}<main><h1>Installez-la chez vous</h1><a href="{{ constant('App\\Instance\\SelfHostingPage::GUIDE') }}">Le guide</a></main>{% endblock %}
            TWIG);
        $_SERVER['BRANDING_DIR'] = $_ENV['BRANDING_DIR'] = $this->tmp;
        $client = static::createClient();

        $client->request('GET', '/auto-hebergement');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Installez-la chez vous');
        $this->assertSelectorExists('a[href="'.SelfHostingPage::GUIDE.'"]');
        $this->assertSelectorExists('link[rel="canonical"][href="http://localhost/auto-hebergement"]');
        $this->assertSelectorNotExists('meta[name="robots"]');
        $this->assertSelectorExists('.site-footer a[href="/auto-hebergement"]');

        $client->request('GET', '/sitemap.xml');
        $this->assertStringContainsString('<loc>http://localhost/auto-hebergement</loc>', (string) $client->getResponse()->getContent());
    }
}
