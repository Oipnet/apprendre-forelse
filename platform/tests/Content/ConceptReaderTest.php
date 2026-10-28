<?php

namespace App\Tests\Content;

use App\Content\ConceptReader;
use App\Content\ContentException;
use App\Content\Framework\FrameworkRegistry;
use App\Content\PackFiles;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/** L'intro d'une notion et le rangement en thèmes se lisent seuls, sans monter de pack. */
final class ConceptReaderTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/concept-reader-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tmp);
    }

    private function file(string $name, string $content): string
    {
        (new Filesystem())->dumpFile($path = $this->tmp.'/'.$name, $content);

        return $path;
    }

    private function reader(): ConceptReader
    {
        return new ConceptReader(new PackFiles(), new FrameworkRegistry());
    }

    public function testLIntroDonneSonTitreEtSonTexte(): void
    {
        $file = $this->file('voter.md', "---\ntitle: Voter Symfony\n---\n\nUn voter décide.\n");

        $this->assertSame(
            ['markdown' => 'Un voter décide.', 'file' => $file, 'packId' => 'pratique', 'title' => 'Voter Symfony'],
            $this->reader()->intro($file, 'pratique'),
        );
    }

    public function testLesThemesSontRangesParFramework(): void
    {
        $file = $this->file('themes.yaml', "symfony:\n  - {title: Les bases, description: Pour commencer., notions: [Route, Contrôleur]}\n");

        $themes = $this->reader()->themes($file, 'fr');

        $this->assertSame(['symfony'], array_keys($themes));
        $this->assertSame('Les bases', $themes['symfony'][0]->title);
    }

    public function testUneNotionRangeeDeuxFoisEstRefusee(): void
    {
        $file = $this->file('themes.yaml', "symfony:\n  - {title: A, notions: [Route]}\n  - {title: B, notions: [Route]}\n");

        $this->expectException(ContentException::class);
        $this->expectExceptionMessage('la notion « Route » est déjà rangée dans « A »');
        $this->reader()->themes($file, 'fr');
    }
}
