<?php

namespace App\Tests\Content\Check;

use App\Content\Check\ProjectWorkdir;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ProjectWorkdirTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/workdir-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/projet/src', 0o777, true);
        mkdir($this->dir.'/dehors');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testOnEcritDansLeProjet(): void
    {
        (new ProjectWorkdir())->write($this->dir.'/projet', ['src/A.php' => 'a', 'src/Neuf/B.php' => 'b']);

        $this->assertSame('b', file_get_contents($this->dir.'/projet/src/Neuf/B.php'));
    }

    /** Les tests ont remplacé src/ par un lien vers un dossier de celui qui note, entre deux lancements (mutants). */
    public function testUnLienPoseParLesTestsNeFaitPasSortirDuProjet(): void
    {
        rmdir($this->dir.'/projet/src');
        symlink($this->dir.'/dehors', $this->dir.'/projet/src');

        try {
            (new ProjectWorkdir())->write($this->dir.'/projet', ['src/Neuf/B.php' => 'b']);
            $this->fail('Écriture hors du projet.');
        } catch (\RuntimeException) {
        }
        $this->assertFileDoesNotExist($this->dir.'/dehors/Neuf/B.php');

        $this->expectException(\RuntimeException::class);
        (new ProjectWorkdir())->clearCaches($this->dir.'/projet', ['src/cache']);
    }

    public function testPasDeRemonteeParDeuxPoints(): void
    {
        $this->expectException(\RuntimeException::class);

        (new ProjectWorkdir())->write($this->dir.'/projet', ['src/../../dehors/B.php' => 'b']);
    }
}
