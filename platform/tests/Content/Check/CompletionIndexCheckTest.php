<?php

namespace App\Tests\Content\Check;

use App\Content\Check\CheckContext;
use App\Content\Check\CheckResult;
use App\Content\Check\CompletionIndexCheck;
use App\Content\Check\RunReport;
use App\Content\ContentRepository;
use App\Content\EnvironmentRegistry;
use App\Instance\EnvironmentArtifacts;
use App\Instance\InstalledEnvironments;
use App\Version;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/** Les imports que l'apprenant écrit doivent être dans l'index de complétion ; l'index est trouvé par EnvironmentArtifacts. */
final class CompletionIndexCheckTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../../..';
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/completion-check-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tmp);
    }

    /** @param array<string, string> $solution */
    private function check(array $solution): CheckResult
    {
        $environments = new EnvironmentRegistry(self::ROOT.'/environments');
        $content = new ContentRepository([self::ROOT.'/examples/packs'], $environments, new Version(self::ROOT.'/VERSION'));
        $exercise = $content->findExercise('decouverte', '01-bonjour') ?? throw new \LogicException();
        $starting = ['src/Controller/BonjourController.php' => "<?php\nnamespace App\\Controller;\nuse Symfony\\Component\\HttpFoundation\\Response;\n"];
        $context = new CheckContext($environments->get('symfony-8'), new FakeTestRunner(static fn () => RunReport::none('')), $starting, [], $solution);
        $result = new CheckResult($exercise);

        (new CompletionIndexCheck(new EnvironmentArtifacts($this->tmp.'/envs', new InstalledEnvironments(''))))->check($exercise, $context, $result);

        return $result;
    }

    public function testSansIndexLaVerificationEstSignaleeCommeNonFaite(): void
    {
        $result = $this->check([]);

        $this->assertSame([], $result->errors);
        $this->assertStringContainsString('Index de complétion absent (envs/symfony-8.completion.json)', $result->warnings[0]);
    }

    public function testUnImportAbsentDeLIndexEstSignale(): void
    {
        (new Filesystem())->dumpFile($this->tmp.'/envs/'.EnvironmentArtifacts::completionName('symfony-8'), json_encode(['classes' => ['Symfony\\Component\\Routing\\Attribute\\Route' => 1]], \JSON_THROW_ON_ERROR));

        $result = $this->check([
            'src/Salutation.php' => "<?php\n\nnamespace App;\n\nclass Salutation\n{\n}\n",
            'src/Controller/BonjourController.php' => "<?php\nnamespace App\\Controller;\nuse App\\Salutation;\nuse Symfony\\Component\\Finder\\Finder;\nuse Symfony\\Component\\HttpFoundation\\Response;\nuse Symfony\\Component\\Routing\\Attribute\\Route;\n",
        ]);

        $this->assertSame(['Complétion : Symfony\\Component\\Finder\\Finder hors de l\'index de « symfony-8 ». L\'apprenant doit écrire ces imports, l\'éditeur ne les lui proposera pas : ajoutez leur namespace à environments/bin/build-completion.php.'], $result->errors);
    }
}
