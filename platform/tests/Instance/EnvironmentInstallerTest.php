<?php

namespace App\Tests\Instance;

use App\Content\ContentException;
use App\Content\EnvironmentRegistry;
use App\Instance\EnvironmentInstaller;
use App\Instance\InstalledEnvironment;
use App\Instance\InstalledEnvironments;
use App\Tests\GitIsolationTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * L'installation d'un environnement depuis un dépôt Git.
 *
 * Les cas qui comptent sont ceux du refus : ce service clone une adresse fournie par un humain puis
 * exécute Composer sur ce qu'il a cloné. Ce qu'il **n'accepte pas** vaut donc d'être épinglé — une
 * adresse `file://`, un dépôt qui n'est pas un environnement, un identifiant qui en masquerait un autre.
 */
final class EnvironmentInstallerTest extends TestCase
{
    use GitIsolationTrait;

    private const string ROOT = __DIR__.'/../../..';

    private string $tmp;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        if (null === (new ExecutableFinder())->find('git')) {
            $this->markTestSkipped('git est introuvable.');
        }
        $this->filesystem = new Filesystem();
        $this->tmp = sys_get_temp_dir().'/install-'.bin2hex(random_bytes(6));
        $this->filesystem->mkdir([$this->tmp.'/installes', $this->tmp.'/maison']);

        // Un « chez-soi » par test : la redirection posée par `git config --global` (plus bas) écrit dans sa
        // configuration git, jamais dans celle de la machine, et ne survit pas au test.
        $this->isolateGitConfig($this->tmp.'/maison');
    }

    protected function tearDown(): void
    {
        $this->restoreGitConfig();
        $this->filesystem->remove($this->tmp);
    }

    /** @return iterable<string, array{string, string}> */
    public static function adressesRefusees(): iterable
    {
        yield 'un chemin local' => ['file:///etc/passwd', 'doit commencer par « https:// »'];
        yield 'du git en clair' => ['git://exemple.test/depot.git', 'doit commencer par « https:// »'];
        yield 'une clé du serveur' => ['git@github.com:oipnet/depot.git', 'doit commencer par « https:// »'];
        yield 'du http simple' => ['http://exemple.test/depot.git', 'doit commencer par « https:// »'];
    }

    #[DataProvider('adressesRefusees')]
    public function testUneAdresseQuiNestPasHttpsEstRefusee(string $url, string $message): void
    {
        $this->expectException(ContentException::class);
        $this->expectExceptionMessage($message);
        $this->installer()->install($url);
    }

    public function testUnHoteHorsDeLaListeAutoriseeEstRefuse(): void
    {
        $this->expectException(ContentException::class);
        $this->expectExceptionMessage('Hôte « ailleurs.test » non autorisé');
        $this->installer(allowlist: 'github.com, gitlab.example')->install('https://ailleurs.test/depot.git');
    }

    public function testUnHoteDeLaListeAutoriseePasseLaVerification(): void
    {
        // L'adresse est acceptée : c'est le clone qui échoue ensuite, faute de dépôt à cette adresse.
        $this->expectException(ContentException::class);
        $this->expectExceptionMessage('Clonage : échec.');
        $this->installer(allowlist: 'github.invalid, gitlab.example')->install('https://github.invalid/depot.git');
    }

    public function testUnIdentifiantQuiNestPasUnNomDeDossierEstRefuse(): void
    {
        $this->expectException(ContentException::class);
        $this->expectExceptionMessage('invalide');
        InstalledEnvironments::id('../../etc');
    }

    /** Sans dossier écrivable, la fonctionnalité est absente et le dit. */
    public function testSansDossierInstallableLInstallationEstRefusee(): void
    {
        $installed = new InstalledEnvironments($this->tmp.'/nexiste-pas');

        $this->assertFalse($installed->isEnabled());

        $this->expectException(ContentException::class);
        $this->expectExceptionMessage('n\'existe pas ou n\'est pas écrivable');
        $this->installer($installed)->install('https://github.com/oipnet/quelque-chose.git');
    }

    /** L'installation garde une trace dès le clonage : un échec ne doit pas disparaître en silence. */
    public function testUnClonageQuiEchoueLaisseUneTraceVisible(): void
    {
        $installed = new InstalledEnvironments($this->tmp.'/installes');

        try {
            $this->installer($installed)->install('https://github.invalid/depot-absent.git');
            $this->fail('Le clonage aurait dû échouer.');
        } catch (ContentException) {
        }

        $jobs = $installed->jobs();
        $this->assertCount(1, $jobs);
        $this->assertSame(InstalledEnvironment::FAILED, $jobs[0]['state']);
        $this->assertSame('https://github.invalid/depot-absent.git', $jobs[0]['url']);
        $this->assertStringContainsString('Clonage', $jobs[0]['message']);

        $installed->forgetJob($jobs[0]['key']);
        $this->assertSame([], $installed->jobs());
    }

    /** Le clone doit être un environnement : c'est environment.yaml qui le dit, et qui le nomme. */
    public function testUnDepotSansEnvironmentYamlEstRefuse(): void
    {
        $depot = $this->depot(['README.md' => '# pas un environnement']);
        $installed = new InstalledEnvironments($this->tmp.'/installes');

        try {
            $this->installer($installed, url: $depot)->install('https://exemple.test/depot.git');
            $this->fail('Un dépôt sans environment.yaml aurait dû être refusé.');
        } catch (ContentException $e) {
            $this->assertStringContainsString('aucun environment.yaml', $e->getMessage());
        }
        $this->assertSame(InstalledEnvironment::FAILED, $installed->jobs()[0]['state']);
    }

    /** Un environnement installé ne masque jamais un environnement du moteur. */
    public function testUnIdentifiantDejaPrisParLeMoteurEstRefuse(): void
    {
        $depot = $this->depot(['environment.yaml' => "id: symfony-8\nphp: '8.4'\n"]);
        $installed = new InstalledEnvironments($this->tmp.'/installes');

        try {
            $this->installer($installed, url: $depot)->install('https://exemple.test/depot.git');
            $this->fail('Un identifiant déjà pris aurait dû être refusé.');
        } catch (ContentException $e) {
            $this->assertStringContainsString('existe déjà', $e->getMessage());
        }
    }

    /**
     * Le chemin heureux, sans Composer : un environnement qui prolonge un environnement du moteur n'a
     * ni composer.json ni dépendances à installer — l'empaquetage se réduit à l'archive et à l'index.
     */
    public function testUnEnvironnementCloneEstInstalleEtEmpaquete(): void
    {
        $depot = $this->depot([
            'environment.yaml' => "id: ma-boutique\nextends: symfony-8\ntitle: Ma boutique\n",
            'src/Controller/BoutiqueController.php' => '<?php // à moi',
        ]);
        $installed = new InstalledEnvironments($this->tmp.'/installes');
        $environments = new EnvironmentRegistry([self::ROOT.'/environments', $this->tmp.'/installes']);

        $id = $this->installer($installed, $environments, url: $depot)->install('https://exemple.test/depot.git');

        $this->assertSame('ma-boutique', $id);
        $source = $installed->read('ma-boutique');
        $this->assertNotNull($source);
        $this->assertTrue($source->isReady(), 'État : '.$source->message);
        $this->assertNotSame('', $source->commit);
        $this->assertSame([], $installed->jobs(), 'Une installation aboutie ne laisse pas de trace en cours.');
        // Le dépôt cloné ne garde pas son .git : ce n'est plus un dépôt, c'est un environnement.
        $this->assertDirectoryDoesNotExist($installed->directoryOf('ma-boutique').'/.git');
        // Et le moteur le charge, prolongeant bien symfony-8.
        $environments->reset();
        $environnement = $environments->get('ma-boutique');
        $this->assertTrue($environnement->isComposed());
        $this->assertNotNull($environnement->file('src/Kernel.php'), 'Le squelette vient de symfony-8.');
        $this->assertNotNull($environnement->file('src/Controller/BoutiqueController.php'));
        // L'archive et l'index ont été produits à côté, hors de l'arborescence publique.
        $this->assertFileExists($installed->artifactsDirectory().'/ma-boutique.zip');
        $this->assertFileExists($installed->artifactsDirectory().'/ma-boutique.completion.json');
    }

    /** @param array<string, string> $fichiers */
    private function depot(array $fichiers): string
    {
        $depot = $this->tmp.'/depot';
        foreach ($fichiers as $chemin => $contenu) {
            $this->filesystem->dumpFile($depot.'/'.$chemin, $contenu);
        }
        foreach ([
            ['git', 'init', '--quiet', '--initial-branch=main'],
            ['git', 'config', 'user.email', 'test@example.test'],
            ['git', 'config', 'user.name', 'Test'],
            ['git', 'add', '-A'],
            ['git', 'commit', '--quiet', '-m', 'environnement'],
        ] as $commande) {
            (new Process($commande, $depot))->mustRun();
        }

        return $depot;
    }

    private function installer(
        ?InstalledEnvironments $installed = null,
        ?EnvironmentRegistry $environments = null,
        string $allowlist = '',
        ?string $url = null,
    ): EnvironmentInstaller {
        $installed ??= new InstalledEnvironments($this->tmp.'/installes');
        $environments ??= new EnvironmentRegistry([self::ROOT.'/environments', $this->tmp.'/installes']);
        $installer = new EnvironmentInstaller(
            $installed,
            $environments,
            self::ROOT.'/environments/bin/build-env.sh',
            $allowlist,
        );

        // Un dépôt local sert de source : le test ne sort pas sur le réseau. L'installateur n'accepte
        // que des adresses https, on lui apprend donc à joindre celle-ci comme git le ferait.
        return null === $url ? $installer : $this->redirige($installer, $url);
    }

    /** Fait pointer « https://exemple.test/depot.git » sur un dépôt du disque, via la configuration de git. */
    private function redirige(EnvironmentInstaller $installer, string $depot): EnvironmentInstaller
    {
        (new Process(['git', 'config', '--global', 'url.'.$depot.'.insteadOf', 'https://exemple.test/depot.git']))->mustRun();
        (new Process(['git', 'config', '--global', 'protocol.file.allow', 'always']))->mustRun();

        return $installer;
    }
}
