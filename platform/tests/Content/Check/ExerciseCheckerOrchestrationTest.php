<?php

namespace App\Tests\Content\Check;

use App\Content\Check\CompletionIndexCheck;
use App\Content\Check\ExerciseCheck;
use App\Content\Check\ExerciseChecker;
use App\Content\Check\MutantGrader;
use App\Content\Check\NodeModuleRunner;
use App\Content\Check\PhpunitRunner;
use App\Content\Check\PracticeVersionCheck;
use App\Content\Check\ProjectWorkdir;
use App\Content\Check\RunReport;
use App\Content\Check\StructureCheck;
use App\Content\Check\TestRunner;
use App\Content\Check\TestRunners;
use App\Content\ContentException;
use App\Content\ContentRepository;
use App\Content\EnvironmentRegistry;
use App\Content\Framework\FrameworkProfile;
use App\Content\Framework\FrameworkRegistry;
use App\Version;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Filesystem\Filesystem;

/**
 * ExerciseChecker orchestre : les vérifications dans l'ordre, puis le lanceur du framework, avant puis après la
 * solution. Le lanceur est factice ici : ExerciseCheckerTest et NuxtExerciseCheckerTest lancent les vrais.
 */
final class ExerciseCheckerOrchestrationTest extends KernelTestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        // Un environnement minuscule : reconstituer le projet ne copie que quelques fichiers.
        $this->tmp = sys_get_temp_dir().'/checker-orchestration-'.bin2hex(random_bytes(4));
        $filesystem = new Filesystem();
        foreach ([
            'environments/mini/environment.yaml' => "id: mini\ntitle: Mini\nphp: '8.4'\n",
            'environments/mini/src/Kernel.php' => "<?php\n",
            'packs/p/pack.yaml' => "id: p\ntitle: P\ntracks: [t]",
            'packs/p/tracks/t/track.yaml' => "id: t\ntitle: T\ndescription: D.\nenvironment: mini\nchapters:\n  - {id: c1, title: C, exercises: [01-bonjour]}",
            'packs/p/tracks/t/exercises/01-bonjour/exercise.yaml' => "id: 01-bonjour\ntitle: Bonjour\nconcepts: [Route]\neditable: [src/Bonjour.php]\nobjectives:\n  - {test: testLaPageRepond, label: Elle répond}\n  - {test: testLaPageDitBonjour, label: Elle dit bonjour}",
            'packs/p/tracks/t/exercises/01-bonjour/instructions.md' => "Dites bonjour.\n",
            'packs/p/tracks/t/exercises/01-bonjour/starter/src/Bonjour.php' => "<?php\n",
            'packs/p/tracks/t/exercises/01-bonjour/solution/src/Bonjour.php' => "<?php echo 'Bonjour';\n",
            'packs/p/tracks/t/exercises/01-bonjour/tests/BonjourTest.php' => "<?php\nfunction testLaPageRepond() {}\nfunction testLaPageDitBonjour() {}\n",
        ] as $path => $content) {
            $filesystem->dumpFile($this->tmp.'/'.$path, $content);
        }
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tmp);
        parent::tearDown();
    }

    public function testLeConteneurChoisitLeLanceurDuFramework(): void
    {
        $runners = static::getContainer()->get(TestRunners::class);
        $frameworks = new FrameworkRegistry();

        $this->assertInstanceOf(PhpunitRunner::class, $runners->for($frameworks->get('symfony')));
        $this->assertInstanceOf(PhpunitRunner::class, $runners->for($frameworks->get('docker')));
        $this->assertInstanceOf(NodeModuleRunner::class, $runners->for($frameworks->get('nuxt')));
    }

    public function testLesVerificationsSontDansLOrdre(): void
    {
        $checker = static::getContainer()->get(ExerciseChecker::class);
        $checks = iterator_to_array((new \ReflectionProperty($checker, 'checks'))->getValue($checker), false);

        $this->assertSame([StructureCheck::class, PracticeVersionCheck::class, CompletionIndexCheck::class], array_map(get_class(...), $checks));
    }

    public function testUnLanceurInconnuEstSignale(): void
    {
        $profile = (new FrameworkRegistry())->get('symfony');
        $inconnu = new FrameworkProfile(...[...get_object_vars($profile), 'testRunner' => 'pytest']);

        $this->expectException(ContentException::class);
        $this->expectExceptionMessage('aucun lanceur de tests « pytest »');
        (new TestRunners(new ServiceLocator([])))->for($inconnu);
    }

    public function testRougeAuDepartVertAvecLaSolution(): void
    {
        $appels = 0;
        $runner = new FakeTestRunner(static function () use (&$appels): RunReport {
            $statut = 0 === $appels++ ? 'failed' : 'passed';

            return RunReport::of([
                'testLaPageRepond' => ['status' => 'passed', 'file' => '/w/tests/BonjourTest.php'],
                'testLaPageDitBonjour' => ['status' => $statut, 'file' => '/w/tests/BonjourTest.php'],
            ], 'failed' === $statut ? ['testLaPageDitBonjour' => 'Pas de bonjour.'] : []);
        });
        [$content, $checker] = $this->checker($runner);

        $result = $checker->check($content->findExercise('t', '01-bonjour') ?? throw new \LogicException());

        $this->assertSame([], $result->errors);
        $this->assertSame([true, false], $result->objectivesBefore);
        $this->assertCount(2, $runner->runs);
    }

    public function testUneSolutionQuiEchoueEstSignaleeAvecLeMessageDuTest(): void
    {
        $runner = new FakeTestRunner(static fn () => RunReport::of(
            ['testLaPageRepond' => ['status' => 'failed', 'file' => '/w/tests/BonjourTest.php']],
            ['testLaPageRepond' => 'Code 500.'],
        ));
        [$content, $checker] = $this->checker($runner);

        $result = $checker->check($content->findExercise('t', '01-bonjour') ?? throw new \LogicException());

        $this->assertContains('Solution : le test testLaPageRepond échoue — Code 500.', $result->errors);
        $this->assertContains('Solution : l\'objectif « testLaPageDitBonjour » n\'a pas été exécuté.', $result->errors);
    }

    public function testSansRapportLaRaisonEstDonnee(): void
    {
        [$content, $checker] = $this->checker(new FakeTestRunner(static fn () => RunReport::none('PHP a planté (signal 11).')));

        $result = $checker->check($content->findExercise('t', '01-bonjour') ?? throw new \LogicException());

        $this->assertSame([
            'État de départ : PHPUnit n\'a produit aucun rapport. PHP a planté (signal 11).',
            'Solution : PHPUnit n\'a produit aucun rapport. PHP a planté (signal 11).',
        ], $result->errors);
    }

    public function testUnLanceurIndisponibleArreteAvantDeLancer(): void
    {
        $runner = new FakeTestRunner(static fn () => throw new \LogicException('Rien ne doit tourner.'), 'Node.js est introuvable.');
        [$content, $checker] = $this->checker($runner);

        $result = $checker->check($content->findExercise('t', '01-bonjour') ?? throw new \LogicException());

        $this->assertSame(['Node.js est introuvable.'], $result->errors);
    }

    public function testUnObjectifSansTestDeCeNomArreteAvantDeLancer(): void
    {
        $runner = new FakeTestRunner(static fn () => throw new \LogicException('Rien ne doit tourner.'), declared: ['testLaPageRepond']);
        [$content, $checker] = $this->checker($runner);

        $result = $checker->check($content->findExercise('t', '01-bonjour') ?? throw new \LogicException());

        $this->assertSame(['Objectif « testLaPageDitBonjour » : aucun test factice de ce nom dans tests/.'], $result->errors);
    }

    /** @return array{ContentRepository, ExerciseChecker} */
    private function checker(TestRunner $runner): array
    {
        $environments = new EnvironmentRegistry($this->tmp.'/environments');
        $content = new ContentRepository([$this->tmp.'/packs'], $environments, new Version(__DIR__.'/../../../../VERSION'));
        $workdirs = new ProjectWorkdir();
        /** @var list<ExerciseCheck> $checks les vérifications sans index de complétion : il n'est pas construit ici */
        $checks = [new StructureCheck(), new PracticeVersionCheck($content)];

        return [$content, new ExerciseChecker(
            $content,
            $environments,
            new TestRunners(new ServiceLocator([FrameworkProfile::PHPUNIT => static fn () => $runner])),
            new MutantGrader($workdirs),
            $checks,
            $workdirs,
        )];
    }
}
