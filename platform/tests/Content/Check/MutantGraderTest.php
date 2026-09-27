<?php

namespace App\Tests\Content\Check;

use App\Content\Check\MutantGrader;
use App\Content\Check\ProjectWorkdir;
use App\Content\Check\RunReport;
use App\Content\ContentRepository;
use App\Content\EnvironmentRegistry;
use App\Content\Exercise;
use App\Content\Objective;
use App\Version;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/** La notation des tests de l'apprenant, avec un lanceur factice : le mutant est appliqué, puis retiré. */
final class MutantGraderTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../../..';
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/mutant-grader-'.bin2hex(random_bytes(4));
        $filesystem = new Filesystem();
        foreach ([
            'pack.yaml' => "id: p\ntitle: P\ntracks: [t]",
            'tracks/t/track.yaml' => "id: t\ntitle: T\ndescription: D.\nenvironment: symfony-8\nchapters:\n  - {id: c1, title: C, exercises: [e1]}",
            'tracks/t/exercises/e1/exercise.yaml' => "id: e1\ntitle: E\nconcepts: [Tests]\neditable: [src/Bonjour.php, tests/MesTest.php]\nobjectives:\n  - {test: own-tests, label: Vos tests passent}\n  - {test: 'mutant:salut', label: Détecte le salut}\nmutants:\n  - id: salut\n    label: la page dit salut\n    changes:\n      - {file: src/Bonjour.php, search: Bonjour, replace: Salut}",
            'tracks/t/exercises/e1/instructions.md' => "Testez.\n",
        ] as $path => $content) {
            $filesystem->dumpFile($this->tmp.'/p/'.$path, $content);
        }
        $filesystem->dumpFile($this->tmp.'/w/src/Bonjour.php', '<?php echo "Bonjour";');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tmp);
    }

    private function exercise(): Exercise
    {
        $content = new ContentRepository([$this->tmp.'/p'], new EnvironmentRegistry(self::ROOT.'/environments'), new Version(self::ROOT.'/VERSION'));

        return $content->findExercise('t', 'e1') ?? throw new \LogicException();
    }

    private static function mesTests(string $status): RunReport
    {
        return RunReport::of(['testBonjour' => ['status' => $status, 'file' => '/w/tests/MesTest.php']]);
    }

    public function testUnMutantDetecteEstNoteEtLeCodeRestaure(): void
    {
        $vus = [];
        $runner = new FakeTestRunner(static function (string $workdir) use (&$vus): RunReport {
            $vus[] = (string) file_get_contents($workdir.'/src/Bonjour.php');

            return self::mesTests('failed');
        });
        $exercise = $this->exercise();

        $grade = (new MutantGrader(new ProjectWorkdir()))->grade($exercise, self::mesTests('passed'), $runner, (new EnvironmentRegistry(self::ROOT.'/environments'))->get('symfony-8'), $this->tmp.'/w');

        $this->assertTrue($grade->results[Objective::OWN_TESTS]);
        $this->assertTrue($grade->results[Objective::MUTANT_PREFIX.'salut']);
        $this->assertSame(['<?php echo "Salut";'], $vus, 'Les tests tournent sur le code muté.');
        $this->assertSame([['tests/MesTest.php']], array_map(static fn (array $run) => $run['paths'], $runner->runs), 'Seuls les tests de l\'apprenant sont relancés.');
        $this->assertSame('<?php echo "Bonjour";', file_get_contents($this->tmp.'/w/src/Bonjour.php'), 'Le code est restauré.');
    }

    public function testUnMutantNonDetecteEstExplique(): void
    {
        $runner = new FakeTestRunner(static fn () => self::mesTests('passed'));

        $grade = (new MutantGrader(new ProjectWorkdir()))->grade($this->exercise(), self::mesTests('passed'), $runner, (new EnvironmentRegistry(self::ROOT.'/environments'))->get('symfony-8'), $this->tmp.'/w');

        $this->assertFalse($grade->results[Objective::MUTANT_PREFIX.'salut']);
        $this->assertSame('les tests passent encore quand la page dit salut.', $grade->failure(Objective::MUTANT_PREFIX.'salut'));
    }

    public function testDesTestsQuiEchouentSurLaBonneApplicationNeNotentAucunMutant(): void
    {
        $runner = new FakeTestRunner(static fn () => throw new \LogicException('Aucun mutant ne doit être lancé.'));

        $grade = (new MutantGrader(new ProjectWorkdir()))->grade($this->exercise(), self::mesTests('failed'), $runner, (new EnvironmentRegistry(self::ROOT.'/environments'))->get('symfony-8'), $this->tmp.'/w');

        $this->assertFalse($grade->results[Objective::OWN_TESTS]);
        $this->assertFalse($grade->results[Objective::MUTANT_PREFIX.'salut']);
        $this->assertSame('des tests de l\'apprenant échouent sur l\'application correcte.', $grade->failure(Objective::OWN_TESTS));
    }
}
