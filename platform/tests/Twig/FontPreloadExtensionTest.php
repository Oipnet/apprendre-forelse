<?php

namespace App\Tests\Twig;

use App\Tests\ThemeTrait;
use App\Twig\FontPreloadExtension;
use Pentatrion\ViteBundle\Service\FileAccessor;
use PHPUnit\Framework\TestCase;

/** Les polices préchargées : celles du manifeste Vite, sauf celles que la marque remplace, et rien sans build. */
final class FontPreloadExtensionTest extends TestCase
{
    use ThemeTrait;

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/polices-'.bin2hex(random_bytes(6));
        mkdir($this->tmp.'/public/build/.vite', recursive: true);
        mkdir($this->tmp.'/theme');
    }

    protected function tearDown(): void
    {
        array_map('unlink', [...glob($this->tmp.'/public/build/.vite/*') ?: [], ...glob($this->tmp.'/theme/*') ?: []]);
        foreach (['/public/build/.vite', '/public/build', '/public', '/theme', ''] as $directory) {
            @rmdir($this->tmp.$directory);
        }
    }

    private function extension(string $theme = ''): FontPreloadExtension
    {
        $configs = ['_default' => ['base' => '/build/']];

        return new FontPreloadExtension(new FileAccessor($this->tmp.'/public', $configs), '_default', $configs, self::theme($theme));
    }

    private function writeManifest(): void
    {
        file_put_contents($this->tmp.'/public/build/.vite/manifest.json', json_encode([
            'src/fonts/newsreader-latin.woff2' => ['file' => 'assets/newsreader-latin-AAA.woff2', 'src' => 'src/fonts/newsreader-latin.woff2'],
            'src/fonts/instrument-sans-latin.woff2' => ['file' => 'assets/instrument-sans-latin-BBB.woff2', 'src' => 'src/fonts/instrument-sans-latin.woff2'],
            'src/fonts/jetbrains-mono-latin.woff2' => ['file' => 'assets/jetbrains-mono-latin-CCC.woff2', 'src' => 'src/fonts/jetbrains-mono-latin.woff2'],
        ]));
    }

    public function testLesPolicesDuHautDePageViennentDuManifeste(): void
    {
        $this->writeManifest();

        $this->assertSame(['/build/assets/newsreader-latin-AAA.woff2', '/build/assets/instrument-sans-latin-BBB.woff2'], $this->extension()->urls());
    }

    public function testUnePoliceRemplaceeParLaMarqueNEstPasPrechargee(): void
    {
        $this->writeManifest();
        file_put_contents($this->tmp.'/theme/theme.yaml', "name: A\nfonts:\n  serif: \"Georgia, serif\"\n");

        $this->assertSame(['/build/assets/instrument-sans-latin-BBB.woff2'], $this->extension($this->tmp.'/theme')->urls());
    }

    public function testSansManifesteRienNEstPrecharge(): void
    {
        $this->assertSame([], $this->extension()->urls(), 'Serveur de développement Vite : pas de manifeste.');
    }
}
