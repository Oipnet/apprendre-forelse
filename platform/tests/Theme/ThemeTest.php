<?php

namespace App\Tests\Theme;

use App\Theme\ThemeLoader;
use App\Tests\ThemeTrait;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;

/**
 * Le thème de l'instance : ce que le moteur affiche sans rien, ce qu'un theme.yaml remplace, et ce
 * qu'il refuse. Le point important est la règle « tout ou rien » : dès qu'une instance pose son thème,
 * plus rien de celui du moteur ne subsiste — sinon elle parlerait de Forelse sans le savoir.
 */
final class ThemeTest extends TestCase
{
    use ThemeTrait;

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/theme-'.bin2hex(random_bytes(6));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmp.'/*') ?: []);
        @rmdir($this->tmp);
    }

    public function testSansDossierCEstLeThemeDuMoteur(): void
    {
        $theme = self::theme();

        $this->assertTrue($theme->isDefault());
        $this->assertSame('default', $theme->id());
        $this->assertFalse($theme->usesLegacyFile());
        $this->assertSame('Forelse', $theme->name());
        $this->assertSame('apprendre', $theme->chip());
        $this->assertSame('Forelse · apprendre', $theme->signature());
        $this->assertSame('/img/logo-84.webp', $theme->logoUrl());
        $this->assertSame('', $theme->styles(), 'Sans couleurs déclarées, aucune feuille de styles en ligne.');
        $this->assertNotNull($theme->home()['showcase'], 'Le fil rouge de Forelse s\'affiche sur l\'instance de Forelse.');
    }

    public function testUnThemeYamlRemplaceToutCeQueLeMoteurAffichait(): void
    {
        $this->write("name: Atelier Bigorneau\nchip: coder\n");
        $theme = self::theme($this->tmp);

        $this->assertFalse($theme->isDefault());
        $this->assertSame('instance', $theme->id());
        $this->assertFalse($theme->usesLegacyFile());
        $this->assertSame('Atelier Bigorneau', $theme->name());
        $this->assertSame('Atelier Bigorneau · coder', $theme->signature());
        // Le titre non déclaré retombe sur le nom, jamais sur celui du moteur.
        $this->assertSame('Atelier Bigorneau', $theme->title());
        $this->assertSame('', $theme->url());
        $this->assertNull($theme->logoUrl(), 'Le logo du moteur n\'habille pas une autre marque.');
        $this->assertNull($theme->shareUrl());
        $this->assertNull($theme->iconUrl());
    }

    /** Mode worker : la même instance sert plusieurs requêtes ; un theme.yaml modifié se voit après reset(). */
    public function testUnThemeYamlModifieSeVoitApresReset(): void
    {
        $this->write("name: Atelier Bigorneau\n");
        $theme = self::theme($this->tmp);
        $this->assertSame('Atelier Bigorneau', $theme->name());

        $this->write("name: Atelier Pic Tordu\n");
        $theme->reset();

        $this->assertSame('Atelier Pic Tordu', $theme->name());
    }

    /** Les textes d'accueil du moteur parlent d'une taverne : ils n'ont rien à faire chez quelqu'un d'autre. */
    public function testLesSectionsDAccueilDuMoteurDisparaissentAvecSonTheme(): void
    {
        $this->write("name: Atelier Bigorneau\n");

        $this->assertSame(['showcase' => null, 'author' => null, 'demo' => null], self::theme($this->tmp)->home());
    }

    public function testLesSectionsDAccueilDeclareesSontServies(): void
    {
        $this->write(<<<'YAML'
            name: Atelier Bigorneau
            home:
              showcase:
                title: Un port de pêche
                lead: [Un seul projet, qui grandit.]
            YAML);
        $home = self::theme($this->tmp)->home();

        $this->assertSame('Un port de pêche', $home['showcase']['title']);
        $this->assertNull($home['author'], 'Une section non déclarée reste absente.');
    }

    /** Le piège du YAML : « - Un titre : une suite » devient un tableau, pas une phrase. */
    public function testUnePhraseNonEchappeeEstSignaleeAvecSaCause(): void
    {
        $this->write("name: A\nhome:\n  showcase:\n    title: Un port\n    lead:\n      - Sans lien entre eux : un seul projet.\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('doit être entre guillemets');
        self::theme($this->tmp)->home();
    }

    public function testUnAccueilQuiNEstPasUneListeEstSignale(): void
    {
        $this->write("name: A\nhome: Bienvenue au port\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('« home » doit être une liste de sections (showcase, author, demo)');
        self::theme($this->tmp)->home();
    }

    public function testUneSectionIncompleteEstSignalee(): void
    {
        $this->write("name: A\nhome:\n  author:\n    title: Qui nous sommes\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('« home.author.lead » est obligatoire');
        self::theme($this->tmp)->home();
    }

    /** Le thème est vérifié en entier au chargement : une erreur de l'accueil n'attend pas le rendu de l'accueil. */
    public function testUneErreurEstSignaleeDesLeChargementAvecLeCheminDeLaCle(): void
    {
        $this->write("name: A\nhome:\n  demo:\n    preview:\n      row: Une ligne\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('« home.demo.preview.row » doit être une liste de phrases');
        (new ThemeLoader())->load($this->tmp);
    }

    public function testLeThemeDuMoteurEstUnThemeYamlValable(): void
    {
        $config = (new ThemeLoader())->load('');

        $this->assertTrue($config->isDefault);
        $this->assertSame('Forelse · apprendre à développer', $config->title);
        $this->assertSame('https://forelse.fr', $config->url);
        $this->assertSame(['name' => 'Arnaud Pointet', 'jobTitle' => 'Développeur indépendant'], $config->person);
        $this->assertStringContainsString('Taverne du Dragon Ivre', (string) $config->home['demo']['code']);
    }

    public function testUneInstanceNeDeclarePasDePersonne(): void
    {
        $this->write("name: A\nperson: {name: X, jobTitle: Y}\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('clé « person » inconnue');
        (new ThemeLoader())->load($this->tmp);
    }

    public function testLesCouleursDeviennentDesVariablesCss(): void
    {
        $this->write("name: A\ncolors:\n  accent: '#1f6f8b'\n  background: '#f6f7f9'\nfonts:\n  mono: \"Menlo, monospace\"\n");
        $styles = self::theme($this->tmp)->styles();

        $this->assertStringContainsString('--lp-rust:#1f6f8b', $styles);
        $this->assertStringContainsString('--lp-mono:Menlo, monospace', $styles);
        // Le fond est aussi posé sur <html>, qui le porte avant que la page ne s'affiche.
        $this->assertStringContainsString('html:has(> body.site-page){background:#f6f7f9}', $styles);
    }

    /** L'éditeur a son propre thème sombre : le moteur garde le sien tant que l'instance n'en déclare pas. */
    public function testLeThemeSombreDeLEditeurSuitLaMarqueQuandElleLeDeclare(): void
    {
        $this->write("name: A\ncolors:\n  accent: '#1f6f8b'\n");
        $this->assertStringNotContainsString(':root{', self::theme($this->tmp)->styles());

        $this->write("name: A\neditor:\n  accent: '#5ab0cc'\n  background: '#12171b'\n");
        $styles = self::theme($this->tmp)->styles();
        $this->assertStringContainsString(':root{--accent:#5ab0cc;--bg:#12171b}', $styles);
        // Les pages du site redéfinissent ces variables pour elles : le thème clair n'est pas touché.
        $this->assertStringNotContainsString('body.site-page{', $styles);
    }

    public function testUneCouleurDEditeurQuiNEnEstPasUneEstRefusee(): void
    {
        $this->write("name: A\neditor:\n  accent: bleu\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('« editor.accent » : une couleur hexadécimale est attendue');
        self::theme($this->tmp)->styles();
    }

    public function testUneCouleurQuiNEnEstPasUneEstRefusee(): void
    {
        $this->write("name: A\ncolors:\n  accent: 'red; } body { display: none'\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('colors.accent');
        self::theme($this->tmp)->styles();
    }

    public function testUneCleInconnueEstSignaleeAvecLaListeDesCleAcceptees(): void
    {
        $this->write("name: A\ncouleurs: {}\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('couleurs');
        self::theme($this->tmp)->name();
    }

    public function testUnThemeSansNomEstRefuse(): void
    {
        $this->write("chip: coder\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('« name » est obligatoire');
        self::theme($this->tmp)->name();
    }

    public function testUneImageDeclareeEstServieParLaRouteEtHorodatee(): void
    {
        $this->write("name: A\nlogo: logo.svg\n");
        file_put_contents($this->tmp.'/logo.svg', '<svg/>');
        $url = self::theme($this->tmp)->logoUrl();

        $this->assertNotNull($url);
        $this->assertSame('/theme/instance/'.filemtime($this->tmp.'/logo.svg').'/logo', $url, 'L\'URL porte le thème et la date du fichier : une image qui change change d\'URL.');
    }

    public function testUneImageAbsenteDuDossierEstSignalee(): void
    {
        $this->write("name: A\nlogo: logo.svg\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('logo');
        self::theme($this->tmp)->logoUrl();
    }

    /** Un chemin plutôt qu'un nom de fichier sortirait du dossier du thème. */
    public function testUnCheminAuLieuDUnNomDeFichierEstRefuse(): void
    {
        $this->write("name: A\nicon: ../../etc/passwd\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('sans barre oblique');
        self::theme($this->tmp)->iconUrl();
    }

    /** L'exemple livré avec le moteur doit rester valable : c'est lui qu'on copie pour démarrer. */
    public function testLExempleLivreEstValide(): void
    {
        $theme = self::theme(\dirname(__DIR__, 3).'/examples/themes/atelier-bigorneau');

        $this->assertSame('Atelier Bigorneau', $theme->name());
        $this->assertStringContainsString('--lp-rust:#1f6f8b', $theme->styles());
        $this->assertStringContainsString(':root{--accent:#5ab0cc', $theme->styles());
        $this->assertNotNull($theme->logoUrl());
        $this->assertNotNull($theme->home()['demo']);
    }

    /** Les instances d'avant le renommage n'ont qu'un marque.yaml : il reste lu, au même format, jusqu'à la 4.0. */
    #[IgnoreDeprecations('marque\\.yaml')]
    public function testUnAncienMarqueYamlEstEncoreLu(): void
    {
        $this->write("name: Atelier Bigorneau\n", 'marque.yaml');
        $theme = self::theme($this->tmp);

        $this->expectUserDeprecationMessageMatches('/renommez ce fichier en « theme\\.yaml »/');

        $this->assertSame('Atelier Bigorneau', $theme->name());
        $this->assertSame('instance', $theme->id());
        $this->assertTrue($theme->usesLegacyFile(), 'L\'instance doit être prévenue de renommer son fichier.');
    }

    /** Pendant la transition, un dossier peut avoir les deux fichiers : le nouveau l'emporte. */
    public function testThemeYamlLEmporteSurLAncienMarqueYaml(): void
    {
        $this->write("name: Ancien nom\n", 'marque.yaml');
        $this->write("name: Nouveau nom\n");
        $theme = self::theme($this->tmp);

        $this->assertSame('Nouveau nom', $theme->name());
        $this->assertFalse($theme->usesLegacyFile());
    }

    /** Un message d'erreur nomme le fichier réellement lu, pour qu'on sache lequel corriger. */
    public function testLErreurNommeLeFichierLu(): void
    {
        $this->write("name: A\ncouleurs: {}\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($this->tmp.'/theme.yaml');
        (new ThemeLoader())->load($this->tmp);
    }

    private function write(string $yaml, string $file = 'theme.yaml'): void
    {
        file_put_contents($this->tmp.'/'.$file, $yaml);
    }
}
