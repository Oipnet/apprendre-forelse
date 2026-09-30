<?php

namespace App\Tests\Controller;

use App\Tests\DatabaseTrait;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Marque blanche : une instance qui monte son thème ne doit plus rien afficher du moteur —
 * ni son nom, ni ses images, ni les textes de son accueil —, et ses gabarits doivent l'emporter.
 */
final class ThemeTest extends WebTestCase
{
    use DatabaseTrait;

    private ?string $tmp = null;
    private ?string $before = null;
    private bool $changed = false;

    public function testSansDossierLaPlateformePorteLeThemeDuMoteur(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.lp-header .lp-serif', 'Forelse');
        $this->assertSelectorExists('.lp-sign', 'Le fil rouge de Forelse est celui du moteur.');
    }

    public function testUnThemeMonteRhabilleLesPagesEtLesBalises(): void
    {
        $client = $this->clientAvecTheme(<<<'YAML'
            name: Atelier Bigorneau
            chip: coder
            title: Atelier Bigorneau · apprendre à coder
            tagline: Coder en ligne, sans rien installer.
            colors:
              accent: '#1f6f8b'
            YAML);
        $crawler = $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $contenu = (string) $client->getResponse()->getContent();

        $this->assertStringNotContainsString('Forelse', $contenu, 'Plus rien de la marque du moteur ne doit subsister.');
        $this->assertSelectorTextContains('.lp-header .lp-serif', 'Atelier Bigorneau');
        $this->assertSelectorTextContains('.lp-header .lp-chip', 'coder');
        $this->assertSelectorTextContains('.site-footer .ft-brand p', 'Coder en ligne, sans rien installer.');
        $this->assertSame('Atelier Bigorneau', $crawler->filter('meta[property="og:site_name"]')->attr('content'));
        // Les couleurs sont posées après la feuille de styles, sinon elles ne l'emporteraient pas.
        $this->assertStringContainsString('--lp-rust:#1f6f8b', $contenu);
        // Le logo du moteur n'habille pas une autre marque.
        $this->assertSelectorNotExists('.lp-header img');
    }

    /** Le texte alternatif de l'aperçu de partage reste celui de la page quand elle en a un. */
    public function testLeTexteAlternatifDePartageSuitLaPage(): void
    {
        $client = $this->clientAvecTheme("name: Atelier Bigorneau\ntagline: Coder en ligne.\nshare: partage.svg\n");
        file_put_contents($this->tmp.'/partage.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        $crawler = $client->request('GET', '/');
        $this->assertResponseIsSuccessful();
        $this->assertSame('Atelier Bigorneau : Coder en ligne.', $crawler->filter('meta[property="og:image:alt"]')->attr('content'));
    }

    /** Le fil rouge et « qui est derrière » parlent d'une taverne : ils ne suivent pas une autre marque. */
    public function testLesSectionsDAccueilDuMoteurDisparaissent(): void
    {
        $client = $this->clientAvecTheme("name: Atelier Bigorneau\n");
        $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('.lp-sign');
        $this->assertSelectorNotExists('.lp-demo');
        $this->assertSelectorNotExists('.lp-section.lined');
    }

    public function testUneSectionDeclareeParLInstanceEstAffichee(): void
    {
        $client = $this->clientAvecTheme(<<<'YAML'
            name: Atelier Bigorneau
            home:
              showcase:
                eyebrow: Le fil rouge
                title: Un port de pêche
                lead: [Un seul projet, qui grandit chapitre après chapitre.]
                sign:
                  name: Port-Bigorneau
                  note: Fondé au chapitre 1
            YAML);
        $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.lp-section.dark .lp-h2', 'Un port de pêche');
        $this->assertSelectorTextContains('.lp-sign .name', 'Port-Bigorneau');
    }

    public function testUneImageDuThemeEstServie(): void
    {
        $client = $this->clientAvecTheme("name: Atelier Bigorneau\nlogo: logo.svg\n");
        file_put_contents($this->tmp.'/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        $crawler = $client->request('GET', '/');
        $this->assertResponseIsSuccessful();
        $logo = $crawler->filter('.lp-header img')->attr('src');
        $this->assertNotNull($logo);

        $this->assertStringStartsWith('/theme/instance/', $logo, 'L\'URL nomme le thème : une bascule de thème change d\'URL.');

        $client->request('GET', $logo);
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'image/svg+xml');
    }

    public function testUneImageNonDeclareeNEstPasServie(): void
    {
        $client = $this->clientAvecTheme("name: Atelier Bigorneau\n");
        $client->request('GET', '/theme/instance/1/logo');
        $this->assertResponseStatusCodeSame(404);

        $client->request('GET', '/marque/logo');
        $this->assertResponseStatusCodeSame(404);
    }

    /** L'image d'un autre thème que celui servi n'est pas servie : l'URL ne ment pas sur ce qu'elle désigne. */
    public function testLImageDUnAutreThemeNEstPasServie(): void
    {
        $client = $this->clientAvecTheme("name: Atelier Bigorneau\nlogo: logo.svg\n");
        file_put_contents($this->tmp.'/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        $client->request('GET', '/theme/forelse/'.filemtime($this->tmp.'/logo.svg').'/logo');
        $this->assertResponseStatusCodeSame(404);
    }

    /** L'adresse d'avant le renommage, encore citée par des caches et des emails envoyés, mène à la nouvelle. */
    public function testLAncienneAdresseDUneImageRedirige(): void
    {
        $client = $this->clientAvecTheme("name: Atelier Bigorneau\nlogo: logo.svg\n");
        file_put_contents($this->tmp.'/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        $client->request('GET', '/marque/logo?v=123');

        $this->assertResponseStatusCodeSame(301);
        $this->assertResponseRedirects('/theme/instance/'.filemtime($this->tmp.'/logo.svg').'/logo');
    }

    /** Sans icône déclarée, l'onglet ne doit pas afficher celle du moteur (servie sur /favicon.ico). */
    public function testLIconeDuMoteurNApparaitPasChezUneAutreMarque(): void
    {
        $client = $this->clientAvecTheme("name: Atelier Bigorneau\n");
        $crawler = $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSame('data:,', $crawler->filter('link[rel="icon"]')->attr('href'));
        $this->assertSelectorNotExists('link[rel="apple-touch-icon"]');
    }

    /** Le thème sombre de l'éditeur suit aussi la marque, sur la page d'exercice. */
    public function testLeThemeDeLEditeurSuitLaMarque(): void
    {
        $client = $this->clientAvecTheme("name: Atelier Bigorneau\neditor:\n  accent: '#5ab0cc'\n");
        $client->request('GET', '/parcours/decouverte/01-bonjour');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString(':root{--accent:#5ab0cc}', (string) $client->getResponse()->getContent());
    }

    /** Le thème sombre suit le système, sauf quand la marque impose sa palette (claire) : les deux ne se mélangent pas. */
    public function testLeThemeSombreSuitLeSystemeSaufPaletteDeMarque(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');
        $this->assertSelectorExists('html[data-theme="auto"]', 'Marque du moteur : clair ou sombre, selon le système.');

        self::ensureKernelShutdown();
        $client = $this->clientAvecTheme("name: Atelier Bigorneau\nfonts:\n  serif: \"Georgia, serif\"\n");
        $client->request('GET', '/');
        $this->assertSelectorExists('html[data-theme="auto"]', 'Des polices seules ne touchent pas aux couleurs.');

        self::ensureKernelShutdown();
        file_put_contents($this->tmp.'/theme.yaml', "name: Atelier Bigorneau\ncolors:\n  accent: '#1f6f8b'\n");
        $client = static::createClient();
        $client->request('GET', '/');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('html[data-theme]', 'Une palette de marque reste claire.');
    }

    /** L'échappatoire : ce que theme.yaml ne règle pas, un gabarit déposé le remplace. */
    public function testUnGabaritDeposeRemplaceCeluiDuMoteur(): void
    {
        $client = $this->clientAvecTheme("name: Atelier Bigorneau\n");
        mkdir($this->tmp.'/templates');
        file_put_contents($this->tmp.'/templates/_footer.html.twig', '<footer class="site-footer">Pied de page maison</footer>');

        $client->request('GET', '/');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.site-footer', 'Pied de page maison');
    }

    /** Un gabarit surchargé écrit avant le renommage lit encore « marque » : l'ancien nom reste un alias jusqu'à la 4.0. */
    public function testUnGabaritDeposeLitEncoreLAncienneVariableMarque(): void
    {
        $client = $this->clientAvecTheme("name: Atelier Bigorneau\n");
        mkdir($this->tmp.'/templates');
        file_put_contents($this->tmp.'/templates/_footer.html.twig', '<footer class="site-footer">{{ marque.name }} / {{ theme.name }}</footer>');

        $client->request('GET', '/');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.site-footer', 'Atelier Bigorneau / Atelier Bigorneau');
    }

    /** Une instance d'avant le renommage n'a qu'un marque.yaml : elle garde son habillage jusqu'à la 4.0. */
    #[IgnoreDeprecations('marque\\.yaml')]
    public function testUnAncienMarqueYamlHabilleEncoreLInstance(): void
    {
        $client = $this->clientAvecTheme("name: Atelier Bigorneau\n", 'marque.yaml');
        $this->expectUserDeprecationMessageMatches('/renommez ce fichier en « theme\\.yaml »/');

        $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.lp-header .lp-serif', 'Atelier Bigorneau');
    }

    private function clientAvecTheme(string $yaml, string $file = 'theme.yaml'): KernelBrowser
    {
        $this->tmp = sys_get_temp_dir().'/theme-'.bin2hex(random_bytes(6));
        mkdir($this->tmp);
        file_put_contents($this->tmp.'/'.$file, $yaml);
        if (!$this->changed) {
            $this->before = $_SERVER['BRANDING_DIR'] ?? null;
            $this->changed = true;
        }
        $_SERVER['BRANDING_DIR'] = $_ENV['BRANDING_DIR'] = $this->tmp;

        return static::createClient();
    }

    protected function tearDown(): void
    {
        if ($this->changed) {
            if (null === $this->before) {
                unset($_SERVER['BRANDING_DIR'], $_ENV['BRANDING_DIR']);
            } else {
                $_SERVER['BRANDING_DIR'] = $_ENV['BRANDING_DIR'] = $this->before;
            }
            $this->changed = false;
        }
        if (null !== $this->tmp && is_dir($this->tmp)) {
            foreach ((array) glob($this->tmp.'/templates/*') as $file) {
                unlink((string) $file);
            }
            @rmdir($this->tmp.'/templates');
            foreach ((array) glob($this->tmp.'/*') as $file) {
                unlink((string) $file);
            }
            @rmdir($this->tmp);
        }
        parent::tearDown();
    }
}
