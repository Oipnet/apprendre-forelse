<?php

namespace App\Tests\Content\Author;

use App\Content\Author\ExerciseFiles;
use App\Content\ContentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ExerciseFilesTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/atelier-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tmp);
    }

    public function testEcritRelitEtOublieCeQuiADisparu(): void
    {
        $fichiers = new ExerciseFiles();
        $fichiers->write($this->tmp, [
            'exercise.yaml' => "id: e1\n",
            'instructions.md' => "# E1\n",
            'starter/src/A.php' => '<?php // départ',
            'solution/src/A.php' => '<?php // solution',
            'tests/Taverne/ATest.php' => '<?php // test',
        ]);

        $this->assertSame(
            ['exercise.yaml', 'instructions.md', 'solution/src/A.php', 'starter/src/A.php', 'tests/Taverne/ATest.php'],
            array_keys($fichiers->read($this->tmp)),
        );

        // Le fichier retiré de la liste disparaît du disque, et son dossier vide avec lui.
        $fichiers->write($this->tmp, ['exercise.yaml' => "id: e1\n", 'instructions.md' => "# E1\n"]);

        $this->assertSame(['exercise.yaml', 'instructions.md'], array_keys($fichiers->read($this->tmp)));
        $this->assertDirectoryDoesNotExist($this->tmp.'/starter');
    }

    /** @return iterable<string, array{string}> */
    public static function cheminsRefuses(): iterable
    {
        yield 'remontée de dossier' => ['starter/../../secret.txt'];
        yield 'chemin absolu' => ['/etc/passwd'];
        yield 'emplacement inconnu' => ['config/packages/security.yaml'];
        yield 'dossier sans fichier' => ['starter'];
        yield 'antislash' => ['starter\\src\\A.php'];
    }

    #[DataProvider('cheminsRefuses')]
    public function testUnCheminSuspectEstRefuse(string $chemin): void
    {
        $this->expectException(ContentException::class);

        (new ExerciseFiles())->write($this->tmp, [$chemin => 'oups']);
    }
}
