<?php

namespace App\Tests\Verification;

use App\Entity\VerificationStatus;
use App\Verification\VerificationQueue;
use App\Verification\VerificationRequest;
use App\Verification\VerificationResult;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class VerificationQueueTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/verifications-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testUneDemandeDescendPuisSonVerdictRemonte(): void
    {
        $queue = new VerificationQueue($this->directory);
        $queue->push(new VerificationRequest(3, null, 'exercice', ['src/A.php' => '<?php // code']));

        $request = $queue->pop();
        $this->assertEquals(new VerificationRequest(3, null, 'exercice', ['src/A.php' => '<?php // code']), $request);
        $this->assertNull($queue->pop(), 'Retirée du dossier.');

        $queue->pushResult(new VerificationResult(3, VerificationStatus::Failed, 'objectif manqué'));
        $this->assertEquals([new VerificationResult(3, VerificationStatus::Failed, 'objectif manqué')], $queue->popResults());
        $this->assertSame([], $queue->popResults());
    }

    public function testLesDossiersSontFermesAuxAutresUtilisateurs(): void
    {
        $queue = new VerificationQueue($this->directory);
        $queue->prepare();
        $queue->push(new VerificationRequest(3, 'parcours', 'exercice', []));

        $this->assertSame(0o700, fileperms($this->directory.'/demandes') & 0o777);
        $this->assertSame(0o700, fileperms($this->directory.'/resultats') & 0o777);
        $this->assertSame(0o644, fileperms($this->directory.'/demandes/3.json') & 0o777, 'Le dossier les cache ; le worker doit pouvoir lire un verdict écrit par root.');
    }

    public function testUneDemandeRenvoyeeRemplaceLaPrecedente(): void
    {
        $queue = new VerificationQueue($this->directory);
        $queue->push(new VerificationRequest(3, null, 'exercice', ['a' => 'premier']));
        $queue->push(new VerificationRequest(3, null, 'exercice', ['a' => 'second']));

        $this->assertSame(['a' => 'second'], $queue->pop()?->files);
        $this->assertNull($queue->pop());
    }

    public function testCeQuiEstMalFormeEstJete(): void
    {
        $queue = new VerificationQueue($this->directory);
        $queue->pushResult(new VerificationResult(1, VerificationStatus::Passed));
        file_put_contents($this->directory.'/resultats/2.json', '{"progressId": 2, "status": "pending"}');
        file_put_contents($this->directory.'/resultats/3.json', 'pas du json');
        mkdir($this->directory.'/demandes');
        file_put_contents($this->directory.'/demandes/4.json', '{"progressId": 4, "exerciseId": "e", "files": {"a": 1}}');

        $this->assertEquals([new VerificationResult(1, VerificationStatus::Passed)], $queue->popResults(), 'Un verdict ne remet jamais une réussite en attente.');
        $this->assertNull($queue->pop());
        $this->assertSame([], glob($this->directory.'/*/*.json'));
    }

    public function testEteinteSansDossier(): void
    {
        $queue = new VerificationQueue('');

        $this->assertFalse($queue->isEnabled());
        $this->assertNull($queue->pop());
        $this->assertSame([], $queue->popResults());
    }
}
