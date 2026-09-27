<?php

namespace App\Tests\Instance;

use App\Content\ContentException;
use App\Instance\GitCommandCheckout;
use App\Tests\GitIsolationTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/** Le clone par la commande git du serveur, sur un dépôt local présenté sous une adresse https. */
final class GitCommandCheckoutTest extends TestCase
{
    use GitIsolationTrait;

    private string $tmp;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        if (null === (new ExecutableFinder())->find('git')) {
            $this->markTestSkipped('git est introuvable.');
        }
        $this->filesystem = new Filesystem();
        $this->tmp = sys_get_temp_dir().'/git-checkout-'.bin2hex(random_bytes(6));
        $this->filesystem->mkdir($this->tmp.'/maison');
        // La redirection posée par `git config --global` reste dans la configuration git du test.
        $this->isolateGitConfig($this->tmp.'/maison');
    }

    protected function tearDown(): void
    {
        $this->restoreGitConfig();
        $this->filesystem->remove($this->tmp);
    }

    public function testClonerEtLireLeCommit(): void
    {
        $depot = $this->tmp.'/depot';
        $this->filesystem->dumpFile($depot.'/environment.yaml', "id: ma-boutique\n");
        foreach ([
            ['git', 'init', '--quiet', '--initial-branch=main'],
            ['git', 'config', 'user.email', 'test@example.test'],
            ['git', 'config', 'user.name', 'Test'],
            ['git', 'add', '-A'],
            ['git', 'commit', '--quiet', '-m', 'environnement'],
        ] as $commande) {
            (new Process($commande, $depot))->mustRun();
        }
        (new Process(['git', 'config', '--global', 'url.'.$depot.'.insteadOf', 'https://exemple.test/depot.git']))->mustRun();
        (new Process(['git', 'config', '--global', 'protocol.file.allow', 'always']))->mustRun();

        $checkout = new GitCommandCheckout();
        $checkout->clone('https://exemple.test/depot.git', '', $this->tmp.'/clone');

        $this->assertFileExists($this->tmp.'/clone/environment.yaml');
        $this->assertSame(trim((new Process(['git', 'rev-parse', 'HEAD'], $depot))->mustRun()->getOutput()), $checkout->commit($this->tmp.'/clone'));
    }

    public function testUnDepotAbsentEchoueAvecLaSortieDeGit(): void
    {
        $this->expectException(ContentException::class);
        $this->expectExceptionMessage('Clonage : échec.');
        (new GitCommandCheckout())->clone('https://github.invalid/depot.git', '', $this->tmp.'/clone');
    }
}
