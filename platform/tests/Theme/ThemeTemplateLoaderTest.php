<?php

namespace App\Tests\Theme;

use App\Theme\ThemeSelection;
use App\Theme\ThemeTemplateLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/** Les gabarits suivent le thème actif : une bascule ou un aperçu se voient sans reconstruire le chargeur. */
final class ThemeTemplateLoaderTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/gabarits-'.bin2hex(random_bytes(6));
        (new Filesystem())->dumpFile($this->tmp.'/a/templates/_footer.html.twig', 'pied A');
        (new Filesystem())->dumpFile($this->tmp.'/b/templates/_footer.html.twig', 'pied B');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tmp);
    }

    public function testLesGabaritsSuiventLeThemeActif(): void
    {
        $selection = new class implements ThemeSelection {
            public string $directory = '';

            public function id(): string
            {
                return 'essai';
            }

            public function directory(): string
            {
                return $this->directory;
            }

            public function isPreview(): bool
            {
                return false;
            }
        };
        $loader = new ThemeTemplateLoader($selection);

        $this->assertFalse($loader->exists('_footer.html.twig'), 'Sans thème, le chargeur ne connaît rien.');

        $selection->directory = $this->tmp.'/a';
        $this->assertTrue($loader->exists('_footer.html.twig'));
        $this->assertSame('pied A', $loader->getSourceContext('_footer.html.twig')->getCode());
        $cleA = $loader->getCacheKey('_footer.html.twig');

        $selection->directory = $this->tmp.'/b';
        $this->assertSame('pied B', $loader->getSourceContext('_footer.html.twig')->getCode(), 'L\'ancien thème n\'est pas resservi.');
        $this->assertNotSame($cleA, $loader->getCacheKey('_footer.html.twig'), 'Clé de cache par chemin : deux thèmes ne partagent pas un gabarit compilé.');

        $selection->directory = '';
        $this->assertFalse($loader->exists('_footer.html.twig'));
    }
}
