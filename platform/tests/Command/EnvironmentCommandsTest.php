<?php

namespace App\Tests\Command;

use App\Command\EnvironmentInstallCommand;
use App\Command\EnvironmentSyncCommand;
use App\Content\EnvironmentRegistry;
use App\Instance\EnvironmentArtifacts;
use App\Instance\EnvironmentInstaller;
use App\Instance\InstalledEnvironments;
use App\Instance\PackEnvironments;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * app:environnement:installer et app:environnement:synchroniser, sur des dossiers temporaires. Les services
 * qu'elles appellent ont leurs propres tests (EnvironmentInstallerTest, PackEnvironmentsTest) : ici, ce que la
 * commande en dit et ce qu'elle renvoie. Un socle minuscule remplace symfony-8, pour empaqueter en un instant.
 */
final class EnvironmentCommandsTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../..';

    private string $tmp;
    private Filesystem $filesystem;
    private string|false $home;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->tmp = sys_get_temp_dir().'/env-commands-'.bin2hex(random_bytes(6));
        $this->filesystem->mkdir([$this->tmp.'/installes', $this->tmp.'/packs', $this->tmp.'/maison']);
        $this->filesystem->dumpFile($this->tmp.'/socle/base-test/environment.yaml', "id: base-test\nphp: '8.4'\ntitle: Socle\n");
        $this->filesystem->dumpFile($this->tmp.'/socle/base-test/src/Base.php', '<?php // du socle');
        // La configuration git d'un test (redirection d'adresse) ne doit pas survivre à ce test.
        $this->home = getenv('HOME');
        putenv('HOME='.$this->tmp.'/maison');
        putenv('XDG_CONFIG_HOME='.$this->tmp.'/maison');
    }

    protected function tearDown(): void
    {
        putenv(false === $this->home ? 'HOME' : 'HOME='.$this->home);
        putenv('XDG_CONFIG_HOME');
        $this->filesystem->remove($this->tmp);
    }

    private function installed(?string $directory = null): InstalledEnvironments
    {
        return new InstalledEnvironments($directory ?? $this->tmp.'/installes');
    }

    private function registry(): EnvironmentRegistry
    {
        return new EnvironmentRegistry([$this->tmp.'/socle', $this->tmp.'/installes'], packPaths: [$this->tmp.'/packs']);
    }

    private function installer(InstalledEnvironments $installed): EnvironmentInstaller
    {
        return new EnvironmentInstaller($installed, $this->registry(), self::ROOT.'/environments/bin/build-env.sh');
    }

    private function install(?InstalledEnvironments $installed = null): CommandTester
    {
        $installed ??= $this->installed();

        return new CommandTester(new Command(null, new EnvironmentInstallCommand($this->installer($installed), $installed)));
    }

    private function sync(?InstalledEnvironments $installed = null): CommandTester
    {
        $installed ??= $this->installed();
        $packs = new PackEnvironments($this->registry(), $this->installer($installed), new EnvironmentArtifacts($this->tmp.'/rien-de-public', $installed));

        return new CommandTester(new Command(null, new EnvironmentSyncCommand($packs, $installed)));
    }

    public function testSansDossierInstallableLesDeuxCommandesLeDisent(): void
    {
        $absent = $this->installed($this->tmp.'/absent');

        $installer = $this->install($absent);
        $this->assertSame(Command::FAILURE, $installer->execute(['depot' => 'https://exemple.test/depot.git']));
        $this->assertStringContainsString('INSTALLED_ENVIRONMENTS_DIR', $installer->getDisplay(true));

        $synchro = $this->sync($absent);
        $this->assertSame(Command::FAILURE, $synchro->execute([]));
        $this->assertStringContainsString('INSTALLED_ENVIRONMENTS_DIR', $synchro->getDisplay(true));
    }

    public function testInstallerDepuisUnDepot(): void
    {
        if (null === (new ExecutableFinder())->find('git')) {
            $this->markTestSkipped('git est introuvable.');
        }
        $depot = $this->tmp.'/depot';
        $this->filesystem->dumpFile($depot.'/environment.yaml', "id: ma-boutique\nextends: base-test\ntitle: Ma boutique\n");
        $this->filesystem->dumpFile($depot.'/src/Boutique.php', '<?php // à moi');
        foreach ([['git', 'init', '--quiet', '--initial-branch=main'], ['git', 'config', 'user.email', 'test@example.test'], ['git', 'config', 'user.name', 'Test'], ['git', 'add', '-A'], ['git', 'commit', '--quiet', '-m', 'environnement']] as $commande) {
            (new Process($commande, $depot))->mustRun();
        }
        // Le dépôt local répond à l'adresse https que la commande exige.
        (new Process(['git', 'config', '--global', 'url.'.$depot.'.insteadOf', 'https://exemple.test/depot.git']))->mustRun();
        (new Process(['git', 'config', '--global', 'protocol.file.allow', 'always']))->mustRun();
        $installed = $this->installed();

        $tester = $this->install($installed);

        $this->assertSame(Command::SUCCESS, $tester->execute(['depot' => 'https://exemple.test/depot.git']), $tester->getDisplay());
        $this->assertStringContainsString('« environment: ma-boutique »', $tester->getDisplay(true));
        $this->assertTrue($installed->read('ma-boutique')?->isReady());
    }

    public function testMettreAJourUnEnvironnementJamaisInstalleEchoue(): void
    {
        $tester = $this->install();

        $this->assertSame(Command::FAILURE, $tester->execute(['depot' => 'inconnu']));
        $this->assertStringContainsString('aucune source connue', $tester->getDisplay(true));
    }

    public function testSynchroniserSansEnvironnementDansLesPacks(): void
    {
        $tester = $this->sync();

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('Rien à faire', $tester->getDisplay(true));
    }

    public function testSynchroniserSimulePuisEmpaquetteLesEnvironnementsDesPacks(): void
    {
        $this->filesystem->dumpFile($this->tmp.'/packs/houblon/pack.yaml', "id: houblon\ntitle: houblon\n");
        $this->filesystem->dumpFile($this->tmp.'/packs/houblon/environments/ma-boutique/environment.yaml', "id: ma-boutique\nextends: base-test\n");
        $archive = $this->tmp.'/installes/.artefacts/ma-boutique.zip';

        $simulation = $this->sync();
        $this->assertSame(Command::SUCCESS, $simulation->execute(['--simuler' => true]));
        $this->assertStringContainsString('ma-boutique', $simulation->getDisplay(true));
        $this->assertStringContainsString('1 à empaqueter', $simulation->getDisplay(true));
        $this->assertFileDoesNotExist($archive, 'Une simulation n\'empaquette rien.');

        $synchro = $this->sync();
        $this->assertSame(Command::SUCCESS, $synchro->execute([]), $synchro->getDisplay());
        $this->assertStringContainsString('Prêt : ma-boutique.', $synchro->getDisplay(true));
        $this->assertFileExists($archive);

        $ensuite = $this->sync();
        $this->assertSame(Command::SUCCESS, $ensuite->execute([]));
        $this->assertStringContainsString('Rien à faire', $ensuite->getDisplay(true), 'Empaqueté : plus rien à faire.');
    }
}
