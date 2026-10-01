<?php

namespace App\Tests\Controller;

use App\Tests\DatabaseTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Le contrat des gabarits (voir TemplateContract et docs/themes.md), vu par un thème : chaque point de surcharge est
 * réellement remplacé sur les pages qui l'utilisent, et reçoit les variables que son en-tête « @theme » promet
 * (strict_variables, actif en test, fait échouer la page sur une variable absente).
 */
final class ThemeContractTest extends WebTestCase
{
    use DatabaseTrait;

    private string $tmp;
    private ?string $before = null;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/contrat-'.bin2hex(random_bytes(6));
        $this->before = $_SERVER['BRANDING_DIR'] ?? null;
    }

    protected function tearDown(): void
    {
        if (null === $this->before) {
            unset($_SERVER['BRANDING_DIR'], $_ENV['BRANDING_DIR']);
        } else {
            $_SERVER['BRANDING_DIR'] = $_ENV['BRANDING_DIR'] = $this->before;
        }
        (new Filesystem())->remove($this->tmp);
        parent::tearDown();
    }

    /** Sans thème, les pages du site se rendent : le découpage en points de surcharge n'a rien cassé. */
    public function testSansThemeLesPagesDuSiteSeRendent(): void
    {
        $client = static::createClient();
        foreach (['/', '/parcours', '/connexion', '/inscription', '/pratique'] as $url) {
            $client->request('GET', $url);
            $this->assertResponseIsSuccessful($url);
        }
        $this->assertSelectorExists('.site-header .site-brand');
        $this->assertSelectorExists('.site-footer');
    }

    public function testChaquePointDeSurchargeRemplaceCeluiDuMoteur(): void
    {
        $client = $this->clientAvecThemeQuiSurchargeTout();

        $crawler = $client->request('GET', '/');
        $this->assertResponseIsSuccessful();
        foreach (['base', 'home', '_header', '_footer', 'home/_hero', 'home/_demo', 'home/_promise', 'home/_how', 'home/_showcase', 'home/_tracks', 'home/_track_card', 'home/_ai', 'home/_audience', 'home/_author', 'home/_signup', 'home/_faq'] as $point) {
            $this->assertCount(1, $crawler->filter('[data-surcharge="'.$point.'"]')->slice(0, 1), $point.' n\'est pas remplacé sur l\'accueil.');
        }
        $this->assertMatchesRegularExpression('#^/\S* (pratique|parcours)#', trim($crawler->filter('[data-surcharge="home/_hero"]')->text()), 'Les variables promises sont bien là.');

        // Le catalogue des parcours se sert de la même fiche que l'accueil.
        $crawler = $client->request('GET', '/parcours');
        $this->assertResponseIsSuccessful();
        $this->assertGreaterThan(0, $crawler->filter('[data-surcharge="home/_track_card"]')->count());

        foreach (['/connexion', '/inscription', '/pratique'] as $url) {
            $crawler = $client->request('GET', $url);
            $this->assertResponseIsSuccessful($url);
            $this->assertCount(1, $crawler->filter('[data-surcharge="_header"]'), $url);
            $this->assertCount(1, $crawler->filter('[data-surcharge="_footer"]'), $url);
        }
    }

    public function testLaPageDErreurDuThemeRemplaceCelleDuMoteur(): void
    {
        $this->theme(['bundles/TwigBundle/Exception/error.html.twig' => '<!DOCTYPE html><title>Erreur</title><main data-surcharge="error">{{ status_code }} {{ status_text }}</main>']);
        $client = static::createClient(['debug' => false]);

        $client->request('GET', '/cette-page-n-existe-pas');

        $this->assertResponseStatusCodeSame(404);
        $this->assertSelectorTextContains('[data-surcharge="error"]', '404');
    }

    /** La page d'exercice est verrouillée : un thème qui la dépose ne change rien. */
    public function testUnThemeNeRemplacePasLaPageDExercice(): void
    {
        $this->theme(['exercise/play.html.twig' => '<p data-surcharge="exercice">Éditeur du thème</p>']);
        $client = static::createClient();
        $this->resetDatabase();
        $client->loginUser($this->createUser());

        $crawler = $client->request('GET', '/parcours/decouverte/01-bonjour');

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('[data-playground]'), 'L\'éditeur du moteur est servi.');
        $this->assertCount(0, $crawler->filter('[data-surcharge="exercice"]'));
    }

    private function clientAvecThemeQuiSurchargeTout(): KernelBrowser
    {
        $moteur = \dirname(__DIR__, 2).'/templates/';
        // base et home se remplacent entiers : on part de ceux du moteur, marqués.
        $base = str_replace('<head>', '<head><meta name="surcharge" data-surcharge="base">', (string) file_get_contents($moteur.'base.html.twig'));
        $home = str_replace('<main class="lp">', '<main class="lp"><p data-surcharge="home"></p>', (string) file_get_contents($moteur.'home.html.twig'));

        $this->theme([
            'base.html.twig' => $base,
            'home.html.twig' => $home,
            '_header.html.twig' => '<header class="lp-header" data-surcharge="_header">{{ theme.name }} {{ brand_chip }} {{ login_suite ?? "" }}{% if blog.open %} blog{% endif %}{% if app.user %} {{ app.user.displayName }}{% endif %}</header>',
            '_footer.html.twig' => '<footer class="site-footer" data-surcharge="_footer">{{ theme.name }}{% if moteur_version %} moteur{% endif %}</footer>',
            'home/_hero.html.twig' => '<section data-surcharge="home/_hero">{{ tryUrl }} {{ tryPractice ? "pratique" : "parcours" }}{% if theme.home.demo %}{{ include("home/_demo.html.twig", {demo: theme.home.demo}) }}{% endif %}</section>',
            'home/_demo.html.twig' => '<figure data-surcharge="home/_demo">{{ demo.files|join(", ") }}</figure>',
            'home/_promise.html.twig' => '<section data-surcharge="home/_promise"></section>',
            'home/_how.html.twig' => '<section data-surcharge="home/_how"></section>',
            'home/_showcase.html.twig' => '<section data-surcharge="home/_showcase">{{ showcase.title }}</section>',
            'home/_tracks.html.twig' => '<section data-surcharge="home/_tracks">{{ tracks|length }} {{ upcoming|length }} {{ inviteOnly ? "bêta" : "ouvert" }}{% for item in tracks %}{{ include("home/_track_card.html.twig", {tabbed: false}) }}{% endfor %}</section>',
            'home/_track_card.html.twig' => '<article data-surcharge="home/_track_card">{{ item.track.title }}</article>',
            'home/_ai.html.twig' => '<section data-surcharge="home/_ai"></section>',
            'home/_audience.html.twig' => '<section data-surcharge="home/_audience"></section>',
            'home/_author.html.twig' => '<section data-surcharge="home/_author">{{ theme.home.author.title }} {{ self_hosting.enabled ? "offre" : "dépôt" }}</section>',
            'home/_signup.html.twig' => '<section data-surcharge="home/_signup">{{ inviteOnly ? "bêta" : "ouvert" }} {{ tryUrl }}</section>',
            'home/_faq.html.twig' => '<section data-surcharge="home/_faq">{{ faq|length }}{% for item in faq %} {{ item.question }}{% endfor %}</section>',
        ]);

        return static::createClient();
    }

    /** @param array<string, string> $templates gabarits du thème, nom => code */
    private function theme(array $templates): void
    {
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->tmp.'/theme.yaml', <<<'YAML'
            name: Atelier Contrat
            home:
              showcase: {title: Un port de pêche}
              author: {title: Qui est derrière, lead: Des formateurs.}
              demo: {files: [PortController.php]}
            YAML);
        foreach ($templates as $name => $code) {
            $filesystem->dumpFile($this->tmp.'/templates/'.$name, $code);
        }
        $_SERVER['BRANDING_DIR'] = $_ENV['BRANDING_DIR'] = $this->tmp;
    }
}
