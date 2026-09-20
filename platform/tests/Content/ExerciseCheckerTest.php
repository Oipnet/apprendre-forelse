<?php

namespace App\Tests\Content;

use App\Content\Check\ExerciseChecker;
use App\Content\ContentRepository;
use App\Content\EnvironmentRegistry;
use App\Version;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/** Exécute réellement PHPUnit sur l'environnement symfony-8 (quelques secondes). */
final class ExerciseCheckerTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../..';
    private string $tmp;

    protected function setUp(): void
    {
        if (!is_file(self::ROOT.'/environments/symfony-8/vendor/autoload.php')) {
            $message = 'Environnement symfony-8 non installé (environments/bin/build-env.sh symfony-8).';
            // En intégration continue, s'ignorer reviendrait à croire qu'on teste : on échoue.
            if (filter_var(getenv('CI'), \FILTER_VALIDATE_BOOL)) {
                $this->fail($message);
            }
            $this->markTestSkipped($message);
        }
        $this->tmp = sys_get_temp_dir().'/checker-test-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tmp);
    }

    private function checker(string $packs): array
    {
        $environments = new EnvironmentRegistry(self::ROOT.'/environments');
        $content = new ContentRepository([$packs], $environments, new Version(self::ROOT.'/VERSION'));

        return [$content, new ExerciseChecker($content, $environments)];
    }

    /** En production, DATABASE_URL est une vraie variable d'environnement, pas une valeur du .env : elle doit être cachée aussi. */
    public function testLesVariablesDeLaPlateformeSontCacheesAuProjetTeste(): void
    {
        [, $checker] = $this->checker(self::ROOT.'/examples/packs');
        $env = $checker->isolatedEnv();

        $this->assertSame('test', $env['APP_ENV']);
        $this->assertFalse($env['DATABASE_URL']);
        $this->assertFalse($env['SANDBOX_ORIGIN'], 'Déclarée dans platform/.env, même si Dotenv ne l\'a pas injectée.');
        $this->assertFalse($env['ANTHROPIC_API_KEY']);
    }

    public function testLePackDeDemoEstConforme(): void
    {
        [$content, $checker] = $this->checker(self::ROOT.'/examples/packs');

        foreach ($content->exercisesOf($content->findTrack('decouverte')) as $exercise) {
            $result = $checker->check($exercise);
            $this->assertSame([], $result->errors, $exercise->id);
        }
    }

    public function testLExerciceDePratiqueDuPackDeDemoEstConforme(): void
    {
        [$content, $checker] = $this->checker(self::ROOT.'/examples/packs');

        $this->assertSame([], $checker->check($content->findPractice('exemple-map-request-header')->exercise)->errors);
    }

    public function testUneNouveauteAbsenteDeLEnvironnementEstSignalee(): void
    {
        $filesystem = new Filesystem();
        $filesystem->mirror(self::ROOT.'/examples/packs/demo', $this->tmp.'/demo');
        $yaml = $this->tmp.'/demo/practice/exemple-map-request-header/exercise.yaml';
        $filesystem->dumpFile($yaml, str_replace("version: '8.1'", "version: '99.0'", (string) file_get_contents($yaml)));

        [$content, $checker] = $this->checker($this->tmp);
        $result = $checker->check($content->findPractice('exemple-map-request-header')->exercise);

        $this->assertStringContainsString('La fonctionnalité arrive en 99.0, or l\'environnement « symfony-8 » a symfony/framework-bundle', implode("\n", $result->errors));
    }

    /**
     * Une classe que la solution importe sans que l'index de complétion la connaisse : l'apprenant devrait
     * l'écrire de mémoire. Celles que l'exercice fournit lui-même n'ont, elles, rien à y faire.
     */
    public function testUnImportAbsentDeLIndexDeCompletionEstSignale(): void
    {
        $filesystem = new Filesystem();
        $filesystem->mirror(self::ROOT.'/examples/packs/demo', $this->tmp.'/demo');
        $solution = $this->tmp.'/demo/tracks/decouverte/exercises/01-bonjour/solution/src';
        $filesystem->dumpFile($solution.'/Salutation.php', "<?php\n\nnamespace App;\n\nclass Salutation\n{\n}\n");
        $controller = $solution.'/Controller/BonjourController.php';
        $filesystem->dumpFile($controller, str_replace(
            'use Symfony\Component\HttpFoundation\Response;',
            "use App\Salutation;\nuse Symfony\Component\Finder\Finder;\nuse Symfony\Component\HttpFoundation\Response;",
            (string) file_get_contents($controller),
        ));

        [$content, $checker] = $this->checker($this->tmp);
        $errors = implode("\n", $checker->check($content->findExercise('decouverte', '01-bonjour'))->errors);

        $this->assertStringContainsString('Complétion : Symfony\Component\Finder\Finder hors de l\'index de « symfony-8 »', $errors);
        $this->assertStringNotContainsString('App\Salutation', $errors, 'Une classe fournie par l\'exercice n\'a pas à être dans l\'index.');
    }

    public function testUneSolutionQuiNePassePasEstSignalee(): void
    {
        // Copie du pack de démo, avec une « solution » identique au starter.
        $filesystem = new Filesystem();
        $filesystem->mirror(self::ROOT.'/examples/packs/demo', $this->tmp.'/demo');
        $exercise = $this->tmp.'/demo/tracks/decouverte/exercises/01-bonjour';
        $filesystem->copy($exercise.'/starter/src/Controller/BonjourController.php', $exercise.'/solution/src/Controller/BonjourController.php', true);

        [$content, $checker] = $this->checker($this->tmp);
        $result = $checker->check($content->findExercise('decouverte', '01-bonjour'));

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString('Solution : le test testLaPageRepond échoue', implode("\n", $result->errors));
    }

    /**
     * Exercice où l'apprenant écrit ses tests : notés par « own-tests » et par des mutants.
     * Ajouté à une copie du pack de démo, à la suite de 02-bonjour-prenom.
     */
    private function exerciceDeTests(string $assertionsDeLaSolution): string
    {
        $filesystem = new Filesystem();
        $filesystem->mirror(self::ROOT.'/examples/packs/demo', $this->tmp.'/demo');
        $track = $this->tmp.'/demo/tracks/decouverte';
        $filesystem->dumpFile($track.'/track.yaml', str_replace('      - 02-bonjour-prenom', "      - 02-bonjour-prenom\n      - 03-mes-tests", (string) file_get_contents($track.'/track.yaml')));
        $test = static fn (string $body) => "<?php\n\nnamespace App\\Tests;\n\nuse Symfony\\Bundle\\FrameworkBundle\\Test\\WebTestCase;\n\nclass MesTest extends WebTestCase\n{\n    public function testBonjour(): void\n    {\n        \$client = static::createClient();\n{$body}\n    }\n}\n";
        foreach ([
            'exercise.yaml' => <<<'YAML'
                id: 03-mes-tests
                title: Mes tests
                base: 02-bonjour-prenom
                editable: [tests/MesTest.php]
                mutants:
                  - id: prenom-oublie
                    label: le prénom n'est plus affiché
                    changes: [{file: src/Controller/BonjourController.php, search: "sprintf('Bonjour %s !', $prenom)", replace: "'Bonjour !'"}]
                objectives:
                  - {own-tests: pass, label: Vos tests passent}
                  - {mutant: prenom-oublie, label: Ils voient le prénom disparaître}
                YAML,
            'instructions.md' => 'Testez /bonjour/Ada.',
            'starter/tests/MesTest.php' => $test("        \$this->markTestIncomplete('À vous !');"),
            'solution/tests/MesTest.php' => $test("        \$client->request('GET', '/bonjour/Ada');\n".$assertionsDeLaSolution),
        ] as $path => $content) {
            $filesystem->dumpFile($track.'/exercises/03-mes-tests/'.$path, $content);
        }

        return $this->tmp;
    }

    public function testDesTestsDeLApprenantNotesParDesMutants(): void
    {
        [$content, $checker] = $this->checker($this->exerciceDeTests("        \$this->assertSelectorTextContains('body', 'Bonjour Ada !');"));
        $result = $checker->check($content->findExercise('decouverte', '03-mes-tests'));

        $this->assertSame([], $result->errors);
        $this->assertSame([false, false], $result->objectivesBefore, 'Au départ, test incomplet : rien n\'est validé.');
    }

    public function testUnMutantQueLaSolutionNeDetectePasEstSignale(): void
    {
        // Une solution trop molle : la page répond, mais son contenu n'est pas vérifié.
        [$content, $checker] = $this->checker($this->exerciceDeTests('        $this->assertResponseIsSuccessful();'));
        $result = $checker->check($content->findExercise('decouverte', '03-mes-tests'));

        $this->assertSame(['Solution : le mutant « prenom-oublie » n\'est détecté par aucun test — les tests passent encore quand le prénom n\'est plus affiché.'], $result->errors);
    }
}
