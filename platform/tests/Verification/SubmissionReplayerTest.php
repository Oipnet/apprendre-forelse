<?php

namespace App\Tests\Verification;

use App\Content\Check\MutantGrader;
use App\Content\Check\PhpunitRunner;
use App\Content\Check\ProcessEnvironment;
use App\Content\Check\ProjectWorkdir;
use App\Content\Check\TestRunners;
use App\Content\ContentRepository;
use App\Content\EnvironmentRegistry;
use App\Content\Framework\FrameworkProfile;
use App\Entity\VerificationStatus;
use App\Verification\SubmissionReplayer;
use App\Verification\VerificationRequest;
use App\Verification\VerificationResult;
use App\Version;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

/** Rejoue réellement PHPUnit sur l'environnement symfony-8, avec l'exercice de Pratique du pack de démo (quelques secondes). */
final class SubmissionReplayerTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../..';
    private const string EXERCISE = self::ROOT.'/examples/packs/demo/practice/exemple-map-request-header';
    private const string FILE = 'src/Controller/VersionController.php';

    protected function setUp(): void
    {
        if (!is_file(self::ROOT.'/environments/symfony-8/vendor/autoload.php')) {
            $message = 'Environnement symfony-8 non installé (environments/bin/build-env.sh symfony-8).';
            // Comme ExerciseCheckerTest : ignoré en local, échec en intégration continue.
            filter_var(getenv('CI'), \FILTER_VALIDATE_BOOL) ? $this->fail($message) : $this->markTestSkipped($message);
        }
    }

    private function replayer(): SubmissionReplayer
    {
        $environments = new EnvironmentRegistry(self::ROOT.'/environments');
        $content = new ContentRepository([self::ROOT.'/examples/packs'], $environments, new Version(self::ROOT.'/VERSION'));
        $workdirs = new ProjectWorkdir();
        $runners = new TestRunners(new ServiceLocator([
            FrameworkProfile::PHPUNIT => static fn () => new PhpunitRunner(new ProcessEnvironment(self::ROOT.'/platform')),
        ]));

        return new SubmissionReplayer($content, $environments, $runners, new MutantGrader($workdirs), $workdirs);
    }

    /** @param array<string, string> $files */
    private function replay(array $files, string $exerciseId = 'exemple-map-request-header'): VerificationResult
    {
        return $this->replayer()->replay(new VerificationRequest(5, null, $exerciseId, $files));
    }

    public function testLaSolutionEstConfirmee(): void
    {
        $result = $this->replay([self::FILE => (string) file_get_contents(self::EXERCISE.'/solution/'.self::FILE)]);

        $this->assertSame(VerificationStatus::Passed, $result->status, (string) $result->detail);
    }

    public function testLeCodeDeDepartEstInfirme(): void
    {
        $result = $this->replay([self::FILE => (string) file_get_contents(self::EXERCISE.'/starter/'.self::FILE)]);

        $this->assertSame(VerificationStatus::Failed, $result->status);
        $this->assertStringContainsString('non atteint', (string) $result->detail);
    }

    public function testLesTestsCachesNeSeRemplacentPas(): void
    {
        $result = $this->replay([
            self::FILE => (string) file_get_contents(self::EXERCISE.'/starter/'.self::FILE),
            // Ni éditable, ni écrit avant les tests cachés : ce faux test ne remplace pas le vrai.
            'tests/Pratique/VersionTest.php' => '<?php namespace App\Tests\Pratique; class VersionTest extends \PHPUnit\Framework\TestCase { public function testRien(): void { $this->assertTrue(true); } }',
        ]);

        $this->assertSame(VerificationStatus::Failed, $result->status);
    }

    public function testUnExerciceInconnuNeSeVerifiePas(): void
    {
        $this->assertSame(VerificationStatus::Unverifiable, $this->replay([self::FILE => '<?php'], 'retire')->status);
    }

    public function testSansFichierEditableRienNeSeVerifie(): void
    {
        $this->assertSame(VerificationStatus::Unverifiable, $this->replay(['config/services.yaml' => 'piraté'])->status);
    }
}
