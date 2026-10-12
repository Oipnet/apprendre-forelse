<?php

namespace App\Tests\Verification;

use App\Entity\VerificationStatus;
use App\Instance\ConsoleCommandLine;
use App\Verification\VerificationRequest;
use App\Verification\VerificationResult;
use App\Verification\VerificationSandbox;
use PHPUnit\Framework\TestCase;

/** Le verdict lu dans la sortie du processus rejoué, avec un faux bin/console (tests/Fixtures/verification). */
final class VerificationSandboxTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir().'/sandbox-test-'.bin2hex(random_bytes(4));
        mkdir($this->workDir);
    }

    protected function tearDown(): void
    {
        @rmdir($this->workDir);
    }

    private function verify(string $mode, int $timeout = 10): VerificationResult
    {
        $sandbox = new VerificationSandbox(new ConsoleCommandLine(__DIR__.'/../Fixtures/verification'), null, $timeout, $this->workDir);

        return $sandbox->run(new VerificationRequest(7, null, 'exercice', ['src/A.php' => $mode]));
    }

    public function testLaDerniereLigneDeLaSortieEstLeVerdict(): void
    {
        $result = $this->verify('reussi');

        $this->assertSame(VerificationStatus::Passed, $result->status);
        $this->assertSame(7, $result->progressId);
    }

    public function testUnVerdictPourUneAutreDemandeInfirmeLaReussite(): void
    {
        $this->assertSame(VerificationStatus::Failed, $this->verify('autre-demande')->status);
    }

    public function testSansVerdictLaReussiteEstInfirmee(): void
    {
        $result = $this->verify('muet');

        $this->assertSame(VerificationStatus::Failed, $result->status);
        $this->assertStringContainsString('plantage', (string) $result->detail);
    }

    public function testUneVerificationTropLongueEstInfirmee(): void
    {
        $result = $this->verify('boucle', timeout: 1);

        $this->assertSame(VerificationStatus::Failed, $result->status);
        $this->assertStringContainsString('Plus de 1 s', (string) $result->detail);
    }

    public function testLeProcessusAUnDossierJetableQuiDisparaitApresLui(): void
    {
        $result = $this->verify('tmpdir');

        [$tmp, $cache] = explode('|', (string) $result->detail);
        $this->assertStringStartsWith($this->workDir.'/verification-', $tmp);
        $this->assertStringStartsWith(\dirname($tmp).'/cache', $cache);
        $this->assertDirectoryDoesNotExist(\dirname($tmp), 'Supprimé après la vérification.');
    }

    public function testUnUtilisateurDeBacASableDemandeRoot(): void
    {
        if (0 === posix_geteuid()) {
            $this->markTestSkipped('Lancé en root : setpriv est disponible.');
        }
        $sandbox = new VerificationSandbox(new ConsoleCommandLine(__DIR__.'/../Fixtures/verification'), 65534, 10, $this->workDir);

        $this->assertStringContainsString('root', (string) $sandbox->unavailable());
    }
}
