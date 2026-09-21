<?php

namespace App\Tests\Instance;

use App\Tests\BrandingTrait;
use PHPUnit\Framework\TestCase;

/**
 * L'identité de l'instance : ce que le moteur affiche sans rien, ce qu'un marque.yaml remplace, et ce
 * qu'il refuse. Le point important est la règle « tout ou rien » : dès qu'une instance pose sa marque,
 * plus rien de celle du moteur ne subsiste — sinon elle parlerait de Forelse sans le savoir.
 */
final class BrandingTest extends TestCase
{
    use BrandingTrait;

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/marque-'.bin2hex(random_bytes(6));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmp.'/*') ?: []);
        @rmdir($this->tmp);
    }

    public function testSansDossierCEstLaMarqueDuMoteur(): void
    {
        $marque = self::branding();

        $this->assertTrue($marque->isDefault());
        $this->assertSame('Forelse', $marque->name());
        $this->assertSame('apprendre', $marque->chip());
        $this->assertSame('Forelse · apprendre', $marque->signature());
        $this->assertSame('/img/logo-84.webp', $marque->logoUrl());
        $this->assertSame('', $marque->styles(), 'Sans couleurs déclarées, aucune feuille de styles en ligne.');
        $this->assertNotNull($marque->home()['showcase'], 'Le fil rouge de Forelse s\'affiche sur l\'instance de Forelse.');
    }

    public function testUnMarqueYamlRemplaceToutCeQueLeMoteurAffichait(): void
    {
        $this->write("name: Atelier Bigorneau\nchip: coder\n");
        $marque = self::branding($this->tmp);

        $this->assertFalse($marque->isDefault());
        $this->assertSame('Atelier Bigorneau', $marque->name());
        $this->assertSame('Atelier Bigorneau · coder', $marque->signature());
        // Le titre non déclaré retombe sur le nom, jamais sur celui du moteur.
        $this->assertSame('Atelier Bigorneau', $marque->title());
        $this->assertSame('', $marque->url());
        $this->assertNull($marque->logoUrl(), 'Le logo du moteur n\'habille pas une autre marque.');
        $this->assertNull($marque->shareUrl());
        $this->assertNull($marque->iconUrl());
    }

    /** Les textes d'accueil du moteur parlent d'une taverne : ils n'ont rien à faire chez quelqu'un d'autre. */
    public function testLesSectionsDAccueilDuMoteurDisparaissentAvecSaMarque(): void
    {
        $this->write("name: Atelier Bigorneau\n");

        $this->assertSame(['showcase' => null, 'author' => null, 'demo' => null], self::branding($this->tmp)->home());
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
        $home = self::branding($this->tmp)->home();

        $this->assertSame('Un port de pêche', $home['showcase']['title']);
        $this->assertNull($home['author'], 'Une section non déclarée reste absente.');
    }

    /** Le piège du YAML : « - Un titre : une suite » devient un tableau, pas une phrase. */
    public function testUnePhraseNonEchappeeEstSignaleeAvecSaCause(): void
    {
        $this->write("name: A\nhome:\n  showcase:\n    title: Un port\n    lead:\n      - Sans lien entre eux : un seul projet.\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('doit être entre guillemets');
        self::branding($this->tmp)->home();
    }

    public function testUneSectionIncompleteEstSignalee(): void
    {
        $this->write("name: A\nhome:\n  author:\n    title: Qui nous sommes\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('« home.author.lead » est obligatoire');
        self::branding($this->tmp)->home();
    }

    public function testLesCouleursDeviennentDesVariablesCss(): void
    {
        $this->write("name: A\ncolors:\n  accent: '#1f6f8b'\n  background: '#f6f7f9'\nfonts:\n  mono: \"Menlo, monospace\"\n");
        $styles = self::branding($this->tmp)->styles();

        $this->assertStringContainsString('--lp-rust:#1f6f8b', $styles);
        $this->assertStringContainsString('--lp-mono:Menlo, monospace', $styles);
        // Le fond est aussi posé sur <html>, qui le porte avant que la page ne s'affiche.
        $this->assertStringContainsString('html:has(> body.site-page){background:#f6f7f9}', $styles);
    }

    /** L'éditeur a son propre thème sombre : le moteur garde le sien tant que l'instance n'en déclare pas. */
    public function testLeThemeSombreDeLEditeurSuitLaMarqueQuandElleLeDeclare(): void
    {
        $this->write("name: A\ncolors:\n  accent: '#1f6f8b'\n");
        $this->assertStringNotContainsString(':root{', self::branding($this->tmp)->styles());

        $this->write("name: A\neditor:\n  accent: '#5ab0cc'\n  background: '#12171b'\n");
        $styles = self::branding($this->tmp)->styles();
        $this->assertStringContainsString(':root{--accent:#5ab0cc;--bg:#12171b}', $styles);
        // Les pages du site redéfinissent ces variables pour elles : le thème clair n'est pas touché.
        $this->assertStringNotContainsString('body.site-page{', $styles);
    }

    public function testUneCouleurDEditeurQuiNEnEstPasUneEstRefusee(): void
    {
        $this->write("name: A\neditor:\n  accent: bleu\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('« editor.accent » : une couleur hexadécimale est attendue');
        self::branding($this->tmp)->styles();
    }

    public function testUneCouleurQuiNEnEstPasUneEstRefusee(): void
    {
        $this->write("name: A\ncolors:\n  accent: 'red; } body { display: none'\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('colors.accent');
        self::branding($this->tmp)->styles();
    }

    public function testUneCleInconnueEstSignaleeAvecLaListeDesCleAcceptees(): void
    {
        $this->write("name: A\ncouleurs: {}\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('couleurs');
        self::branding($this->tmp)->name();
    }

    public function testUneMarqueSansNomEstRefusee(): void
    {
        $this->write("chip: coder\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('« name » est obligatoire');
        self::branding($this->tmp)->name();
    }

    public function testUneImageDeclareeEstServieParLaRouteEtHorodatee(): void
    {
        $this->write("name: A\nlogo: logo.svg\n");
        file_put_contents($this->tmp.'/logo.svg', '<svg/>');
        $url = self::branding($this->tmp)->logoUrl();

        $this->assertNotNull($url);
        $this->assertStringStartsWith('/marque/logo?v=', $url, 'L\'URL porte la date du fichier : une image qui change change d\'URL.');
    }

    public function testUneImageAbsenteDuDossierEstSignalee(): void
    {
        $this->write("name: A\nlogo: logo.svg\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('logo');
        self::branding($this->tmp)->logoUrl();
    }

    /** Un chemin plutôt qu'un nom de fichier sortirait du dossier de marque. */
    public function testUnCheminAuLieuDUnNomDeFichierEstRefuse(): void
    {
        $this->write("name: A\nicon: ../../etc/passwd\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('sans barre oblique');
        self::branding($this->tmp)->iconUrl();
    }

    /** L'exemple livré avec le moteur doit rester valable : c'est lui qu'on copie pour démarrer. */
    public function testLExempleLivreEstValide(): void
    {
        $marque = self::branding(\dirname(__DIR__, 3).'/examples/marque');

        $this->assertSame('Atelier Bigorneau', $marque->name());
        $this->assertStringContainsString('--lp-rust:#1f6f8b', $marque->styles());
        $this->assertStringContainsString(':root{--accent:#5ab0cc', $marque->styles());
        $this->assertNotNull($marque->logoUrl());
        $this->assertNotNull($marque->home()['demo']);
    }

    private function write(string $yaml): void
    {
        file_put_contents($this->tmp.'/marque.yaml', $yaml);
    }
}
