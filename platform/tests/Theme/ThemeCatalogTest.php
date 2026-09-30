<?php

namespace App\Tests\Theme;

use App\Theme\ThemeCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/** Les thèmes installés : le moteur, l'ancien BRANDING_DIR, et un dossier par thème dans THEMES_DIR. */
final class ThemeCatalogTest extends TestCase
{
    private string $tmp;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->tmp = sys_get_temp_dir().'/themes-'.bin2hex(random_bytes(6));
        $this->filesystem->mkdir([$this->tmp.'/themes', $this->tmp.'/marque']);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->tmp);
    }

    public function testSansRienSeulLeThemeDuMoteurEstInstalle(): void
    {
        $catalog = new ThemeCatalog('', '');

        $this->assertSame(['default' => ''], $catalog->all());
        $this->assertSame('', $catalog->directory('default'));
        $this->assertNull($catalog->directory('instance'));
        $this->assertSame([], $catalog->ignored());
    }

    /** Une instance d'avant les thèmes multiples garde son dossier : il devient le thème « instance ». */
    public function testLAncienDossierDeMarqueEstLeThemeInstance(): void
    {
        $catalog = new ThemeCatalog('', $this->tmp.'/marque');
        $this->assertFalse($catalog->has('instance'), 'Un dossier vide n\'est pas un thème.');

        $this->filesystem->dumpFile($this->tmp.'/marque/marque.yaml', "name: A\n");
        $this->assertSame($this->tmp.'/marque', $catalog->directory('instance'), 'Même avec l\'ancien nom du fichier.');
    }

    public function testChaqueDossierDeThemesDirEstUnTheme(): void
    {
        $this->filesystem->dumpFile($this->tmp.'/themes/forelse/theme.yaml', "name: Forelse\n");
        $this->filesystem->dumpFile($this->tmp.'/themes/atelier-2/theme.yaml', "name: Atelier\n");
        $catalog = new ThemeCatalog($this->tmp.'/themes', '');

        $this->assertSame(['default', 'atelier-2', 'forelse'], array_keys($catalog->all()));
        $this->assertSame(realpath($this->tmp.'/themes/forelse'), $catalog->directory('forelse'));
    }

    /** Ce qui n'est pas un thème est ignoré, avec la raison que l'admin affiche. */
    public function testLesDossiersQuiNeSontPasDesThemesSontIgnoresAvecLeurRaison(): void
    {
        $this->filesystem->dumpFile($this->tmp.'/themes/Mauvais_Nom/theme.yaml', "name: A\n");
        $this->filesystem->dumpFile($this->tmp.'/themes/default/theme.yaml', "name: A\n");
        $this->filesystem->mkdir($this->tmp.'/themes/vide');
        $this->filesystem->dumpFile($this->tmp.'/ailleurs/theme.yaml', "name: A\n");
        symlink($this->tmp.'/ailleurs', $this->tmp.'/themes/lien');
        $catalog = new ThemeCatalog($this->tmp.'/themes', '');

        $this->assertSame(['default'], array_keys($catalog->all()));
        $ignored = $catalog->ignored();
        $this->assertStringContainsString('nom invalide', $ignored['Mauvais_Nom']);
        $this->assertSame('nom réservé', $ignored['default']);
        $this->assertSame('pas de theme.yaml', $ignored['vide']);
        $this->assertStringContainsString('lien symbolique', $ignored['lien']);
        $this->assertNull($catalog->directory('lien'));
        $this->assertNull($catalog->directory('../ailleurs'), 'Un identifiant n\'est jamais un chemin.');
    }
}
