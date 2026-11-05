<?php

namespace App\Tests\Content;

use App\Content\ContentRepository;
use App\Content\EnvironmentRegistry;
use App\Content\Objective;
use App\Content\StepPhase;
use App\Tests\ExerciseCheckerTrait;
use App\Version;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Un exercice à étapes (rouge, vert, refacto), vérifié étape par étape avec le vrai PHPUnit de l'environnement
 * php-8-tdd : chaque étape part de la solution de la précédente, une étape rouge échoue sur ce code et passe sur
 * celui de l'étape verte suivante.
 */
final class StepExerciseCheckerTest extends TestCase
{
    use ExerciseCheckerTrait;

    private const string ROOT = __DIR__.'/../../..';
    private const string PACK = __DIR__.'/../Fixtures/packs/etapes';
    private const string EXERCISE = '/tracks/tdd/exercises/01-le-premier-bidon';
    private ?string $tmp = null;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/steps-checker-test-'.bin2hex(random_bytes(4));
        (new Filesystem())->mirror(self::PACK, $this->tmp.'/etapes');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tmp);
    }

    /** @return array{ContentRepository, \App\Content\Check\ExerciseChecker} */
    private function checker(): array
    {
        $environments = new EnvironmentRegistry(self::ROOT.'/environments');
        $content = new ContentRepository([$this->tmp], $environments, new Version(self::ROOT.'/VERSION'));

        return [$content, self::exerciseChecker($content, $environments)];
    }

    private function needsEnvironment(): void
    {
        if (!is_file(self::ROOT.'/environments/php-8-tdd/vendor/autoload.php')) {
            $message = 'Environnement php-8-tdd non installé (environments/bin/build-env.sh php-8-tdd).';
            filter_var(getenv('CI'), \FILTER_VALIDATE_BOOL) ? $this->fail($message) : $this->markTestSkipped($message);
        }
    }

    private function edit(string $path, string $search, string $replace): void
    {
        $file = $this->tmp.'/etapes'.self::EXERCISE.'/'.$path;
        $code = (string) file_get_contents($file);
        $this->assertStringContainsString($search, $code, $path);
        file_put_contents($file, str_replace($search, $replace, $code));
    }

    public function testLesEtapesSontLues(): void
    {
        [$content] = $this->checker();
        $exercise = $content->findExercise('tdd', '01-le-premier-bidon');

        $this->assertTrue($exercise->hasSteps());
        $this->assertSame(['rouge-bidon-vide', 'vert-bidon-vide', 'rouge-dix-litres', 'vert-dix-litres', 'refacto-prix'], array_map(static fn ($s) => $s->id, $exercise->steps));
        $this->assertSame(StepPhase::Red, $exercise->steps[0]->phase);
        $this->assertSame(['tests/PaieDuLaitTest.php', 'src/PaieDuLait.php'], $exercise->editable, 'L\'exercice réunit les fichiers modifiables de ses étapes.');
        $this->assertSame('tests/PaieDuLaitTest.php', $exercise->open, 'Il s\'ouvre sur le fichier de la première étape.');
        $this->assertSame([Objective::OWN_TESTS, 'testLePrixDuLitreEstUneConstante', 'mutant:prix-oublie'], array_map(static fn ($o) => $o->test, $exercise->objectives), 'Ses objectifs (vérification d\'une réussite) sont ceux de la dernière étape.');
        $this->assertSame([Objective::OWN_TESTS_FAIL, Objective::OWN_TESTS_REFERENCE], array_map(static fn ($o) => $o->test, $exercise->steps[0]->objectives));
        $this->assertSame("Étape rouge-bidon-vide.\n", $exercise->steps[0]->instructions);
    }

    public function testLeCodeAttenduDUneEtapeRougeEstCeluiDeLEtapeVerteSuivante(): void
    {
        [$content] = $this->checker();
        $exercise = $content->findExercise('tdd', '01-le-premier-bidon');

        $reference = $content->stepReferenceFiles($exercise, 2);

        $this->assertSame(['src/PaieDuLait.php'], array_keys($reference), 'Les tests de l\'apprenant n\'en font pas partie : ce sont les siens qu\'on essaie.');
        $this->assertStringContainsString('return $litres * 50;', $reference['src/PaieDuLait.php']);
        $this->assertStringContainsString('PRIX_DU_LITRE', $content->solutionFiles($exercise)['src/PaieDuLait.php'], 'La solution de l\'exercice est celle de sa dernière étape.');
        $this->assertArrayHasKey('tests/PaieDuLaitTest.php', $content->solutionFiles($exercise), '… par-dessus celles des étapes d\'avant.');
    }

    public function testUnExerciceAEtapesEstConforme(): void
    {
        $this->needsEnvironment();
        [$content, $checker] = $this->checker();

        $result = $checker->check($content->findExercise('tdd', '01-le-premier-bidon'));

        $this->assertSame([], $result->errors);
        // L'index de complétion se construit avec l'archive (build-env.sh) : son absence n'est qu'un avertissement.
        $this->assertSame([], array_values(array_filter($result->warnings, static fn (string $w) => !str_starts_with($w, 'Index de complétion absent'))));
    }

    /** Un test caché du même nom qu'un test de l'apprenant : le rapport ne dirait plus lequel a réussi. */
    public function testUnTestCacheNePortePasLeNomDUnTestDeLApprenant(): void
    {
        $this->needsEnvironment();
        $this->edit('tests/Verification/PaieDuLaitVerificationTest.php', 'testVerifieUnBidonVide', 'testUnBidonVideNeRapporteRien');
        $this->edit('exercise.yaml', 'test: testVerifieUnBidonVide', 'test: testUnBidonVideNeRapporteRien');
        [$content, $checker] = $this->checker();

        $errors = implode("\n", $checker->check($content->findExercise('tdd', '01-le-premier-bidon'))->errors);

        $this->assertStringContainsString('Objectif « testUnBidonVideNeRapporteRien » : les tests de l\'apprenant (solution) ont une méthode de test du même nom', $errors);
    }

    /** Un test qui échoue pour une mauvaise raison (une valeur fausse) ne valide pas une étape rouge. */
    public function testUnTestRougePourUneMauvaiseRaisonEstSignale(): void
    {
        $this->needsEnvironment();
        $this->edit('steps/rouge-dix-litres/solution/tests/PaieDuLaitTest.php', 'assertSame(500,', 'assertSame(400,');
        [$content, $checker] = $this->checker();

        $errors = implode("\n", $checker->check($content->findExercise('tdd', '01-le-premier-bidon'))->errors);

        $this->assertStringContainsString('Étape « rouge-dix-litres », solution : ses tests ne passent pas sur le code attendu', $errors);
    }

    /** Une étape rouge dont le test passe déjà n'a rien de rouge. */
    public function testUnTestDejaVertNeFaitPasUneEtapeRouge(): void
    {
        $this->needsEnvironment();
        $this->edit('steps/rouge-dix-litres/solution/tests/PaieDuLaitTest.php', 'assertSame(500, (new PaieDuLait())->montantEnCentimes(10))', 'assertSame(0, (new PaieDuLait())->montantEnCentimes(10))');
        // Le code attendu doit rester cohérent avec ce test : seule la phase rouge est en cause.
        $this->edit('steps/vert-dix-litres/solution/src/PaieDuLait.php', 'return $litres * 50;', 'return 0;');
        [$content, $checker] = $this->checker();

        $errors = implode("\n", $checker->check($content->findExercise('tdd', '01-le-premier-bidon'))->errors);

        $this->assertStringContainsString('Étape « rouge-dix-litres », solution : aucun test de l\'apprenant n\'échoue', $errors);
    }

    /** Un mutant cité par une étape s'applique au code de cette étape, même si l'apprenant le récrit plus tard. */
    public function testUnMutantSeVerifieSurLeCodeDeSonEtape(): void
    {
        $this->needsEnvironment();
        $this->edit('exercise.yaml', "        label: Dix litres rapportent 500 centimes\n", "        label: Dix litres rapportent 500 centimes\n      - mutant: prix-oublie\n        label: Vos tests remarquent quand le prix est oublié\n");
        $this->edit('exercise.yaml', "      - mutant: prix-oublie\n        label: Vos tests remarquent quand le prix est oublié\n\nmutants:", "\nmutants:");
        $this->edit('exercise.yaml', 'search: \'$litres * self::PRIX_DU_LITRE\'', 'search: \'$litres * 50\'');
        [$content, $checker] = $this->checker();

        $result = $checker->check($content->findExercise('tdd', '01-le-premier-bidon'));

        $this->assertSame([], $result->errors, 'Le texte du mutant est dans le code de l\'étape « vert-dix-litres », plus dans la solution finale.');
    }

    public function testLaDerniereEtapeNePeutPasEtreRouge(): void
    {
        $this->edit('exercise.yaml', "  - id: refacto-prix\n    phase: refactor", "  - id: refacto-prix\n    phase: red");
        [$content] = $this->checker();

        $this->expectExceptionMessageMatches('/étape rouge « refacto-prix »|la dernière étape ne peut pas être rouge/');
        $content->findExercise('tdd', '01-le-premier-bidon');
    }

    public function testUneEtapeRougeSansCodeAttenduEstRefusee(): void
    {
        $this->edit('exercise.yaml', "      - own-tests: reference\n        label: … et passe sur le code attendu\n  - id: vert-bidon-vide", "  - id: vert-bidon-vide");
        [$content] = $this->checker();

        $this->expectExceptionMessage('étape rouge « rouge-bidon-vide » : ses objectifs comptent « own-tests: fail » et « own-tests: reference »');
        $content->findExercise('tdd', '01-le-premier-bidon');
    }

    public function testOwnTestsFailNeSertQuAUneEtapeRouge(): void
    {
        $this->edit('exercise.yaml', "      - own-tests: pass\n        label: Votre test passe", "      - own-tests: fail\n        label: Votre test passe");
        [$content] = $this->checker();

        $this->expectExceptionMessage('« own-tests: fail » ne sert que dans une étape rouge');
        $content->findExercise('tdd', '01-le-premier-bidon');
    }

    public function testLesObjectifsSeReglentParEtape(): void
    {
        $this->edit('exercise.yaml', 'xp: 100', "xp: 100\nobjectives:\n  - {test: testVerifieUnBidonVide, label: X}");
        [$content] = $this->checker();

        $this->expectExceptionMessage('« objectives » se règle étape par étape dans « steps »');
        $content->findExercise('tdd', '01-le-premier-bidon');
    }

    public function testLaConsigneDeChaqueEtapeEstRequise(): void
    {
        unlink($this->tmp.'/etapes'.self::EXERCISE.'/steps/vert-dix-litres/instructions.md');
        [$content] = $this->checker();

        $this->expectExceptionMessage('étape « vert-dix-litres » : steps/vert-dix-litres/instructions.md manquant');
        $content->findExercise('tdd', '01-le-premier-bidon');
    }

    public function testUneTraductionReprendLesEtapesParLeurId(): void
    {
        $filesystem = new Filesystem();
        $overlay = $this->tmp.'/etapes'.self::EXERCISE.'/i18n/en';
        $steps = '';
        foreach (['rouge-bidon-vide' => ['fail', 'reference'], 'vert-bidon-vide' => ['pass'], 'rouge-dix-litres' => ['fail', 'reference'], 'vert-dix-litres' => ['pass'], 'refacto-prix' => ['pass']] as $id => $kinds) {
            $objectives = implode('', array_map(static fn (string $kind) => "      - {own-tests: {$kind}, label: EN {$kind}}\n", $kinds));
            $steps .= "  - id: {$id}\n    title: EN {$id}\n    objectives:\n{$objectives}";
            $filesystem->dumpFile($overlay.'/steps/'.$id.'/instructions.md', "Step {$id}.\n");
        }
        $filesystem->dumpFile($overlay.'/exercise.yaml', "title: The first churn\nmutants:\n  - {id: prix-oublie, label: the price is forgotten, changes: [{file: src/PaieDuLait.php, search: '\$litres * self::PRIX_DU_LITRE', replace: '\$litres'}]}\nsteps:\n{$steps}");
        $filesystem->dumpFile($overlay.'/instructions.md', "Pay the milk.\n");
        $filesystem->dumpFile($this->tmp.'/etapes/tracks/tdd/i18n/en/track.yaml', "title: TDD\ndescription: Steps.\nchapters:\n  - {id: c1, title: C1}\n");
        [$content] = $this->checker();

        $exercise = $content->findExercise('tdd', '01-le-premier-bidon', 'en');

        $this->assertSame('EN rouge-dix-litres', $exercise->steps[2]->title);
        $this->assertSame(StepPhase::Red, $exercise->steps[2]->phase, 'La phase vient de l\'exercice français.');
        $this->assertSame(['tests/PaieDuLaitTest.php'], $exercise->steps[2]->editable);
        $this->assertSame("Step rouge-dix-litres.\n", $exercise->steps[2]->instructions);
    }
}
