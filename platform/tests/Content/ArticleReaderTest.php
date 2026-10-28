<?php

namespace App\Tests\Content;

use App\Content\ArticleReader;
use App\Content\ContentException;
use App\Content\PackFiles;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/** Un article se lit seul, sans monter de pack. */
final class ArticleReaderTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/article-reader-'.bin2hex(random_bytes(4));
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

    public function testUnArticleSeLitAvecSesSujets(): void
    {
        $files = new PackFiles();
        $file = $this->file('le-routage.md', "---\ntitle: Le routage\ndescription: Une   route.\npublished: 2026-10-06\ntags: [Symfony, Sécurité]\n---\n\nDu texte.\n");

        $article = (new ArticleReader($files))->read($file, 'fr', 'pratique');

        $this->assertSame('le-routage', $article->slug);
        $this->assertSame('pratique', $article->packId);
        $this->assertSame('Une route.', $article->description);
        $this->assertSame('2026-10-06', $article->published->format('Y-m-d'));
        $this->assertSame(['Symfony', 'Sécurité'], $article->tags);
        $this->assertSame('Du texte.', $article->markdown);
        $this->assertContains($file, array_keys($files->watched()), 'Le fichier est surveillé pour le cache.');
    }

    public function testUneTraductionNommeLArticleQuElleTraduit(): void
    {
        $file = $this->file('routing.md', "---\ntitle: Routing\ndescription: A route.\npublished: 2026-10-06\n---\nText.\n");

        $this->expectException(ContentException::class);
        $this->expectExceptionMessage('translation_of');
        (new ArticleReader(new PackFiles()))->read($file, 'en', 'pratique');
    }
}
