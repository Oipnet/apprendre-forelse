<?php

namespace App\Tests\Instance;

use App\Content\ContentException;
use App\Content\EnvironmentRegistry;
use App\Instance\EnvironmentInstaller;
use App\Instance\GitCheckout;
use App\Instance\InstallationJobs;
use App\Instance\InstalledEnvironment;
use App\Instance\InstalledEnvironments;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * L'installation d'un environnement depuis un dépôt Git.
 *
 * Les cas qui comptent sont ceux du refus : ce service clone une adresse fournie par un humain puis
 * exécute Composer sur ce qu'il a cloné. Ce qu'il **n'accepte pas** vaut donc d'être épinglé — une
 * adresse `file://`, un dépôt qui n'est pas un environnement, un identifiant qui en masquerait un autre.
 *
 * git est remplacé par un faux qui « clone » un dossier du disque : le test tourne sans git ni réseau
 * (la commande git elle-même est testée par GitCommandCheckoutTest).
 */
final class EnvironmentInstallerTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../..';

    private string $tmp;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->tmp = sys_get_temp_dir().'/install-'.bin2hex(random_bytes(6));
        $this->filesystem->mkdir($this->tmp.'/installes');
    }

    protected function tearDown(): void
    {
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

        $journal = new InstallationJobs($installed);
        $jobs = $journal->all();
        $this->assertCount(1, $jobs);
        $this->assertSame(InstalledEnvironment::FAILED, $jobs[0]['state']);
        $this->assertSame('https://github.invalid/depot-absent.git', $jobs[0]['url']);
        $this->assertStringContainsString('Clonage', $jobs[0]['message']);

        $journal->forget($jobs[0]['key']);
        $this->assertSame([], $journal->all());
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
        $this->assertSame(InstalledEnvironment::FAILED, (new InstallationJobs($installed))->all()[0]['state']);
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
        // Test d'intégration : l'empaquetage lance vraiment build-env.sh, donc composer install (réseau, plusieurs Go
        // pour symfony-8). Il tourne en intégration continue, et en local sur demande (FORELSE_INTEGRATION=1).
        if (!filter_var(getenv('CI'), \FILTER_VALIDATE_BOOL) && !filter_var(getenv('FORELSE_INTEGRATION'), \FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Test d\'intégration (build-env.sh, composer install) : lancé en CI, ou avec FORELSE_INTEGRATION=1.');
        }
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
        $this->assertSame([], (new InstallationJobs($installed))->all(), 'Une installation aboutie ne laisse pas de trace en cours.');
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

        // Un dossier local sert de dépôt à « https://exemple.test/depot.git » : le test ne sort pas sur le réseau.
        return new EnvironmentInstaller(
            $installed,
            new InstallationJobs($installed),
            new FakeGitCheckout(null === $url ? [] : ['https://exemple.test/depot.git' => $url]),
            $environments,
            self::ROOT.'/environments/bin/build-env.sh',
            $allowlist,
        );
    }
}

/** Un « clone » qui recopie un dossier du disque ; toute autre adresse échoue comme git. */
final readonly class FakeGitCheckout implements GitCheckout
{
    /** @param array<string, string> $depots dossier par adresse */
    public function __construct(private array $depots)
    {
    }

    public function clone(string $url, string $ref, string $destination): void
    {
        if (!isset($this->depots[$url])) {
            throw new ContentException(sprintf('Clonage : échec. fatal: unable to access \'%s\'', $url));
        }
        (new Filesystem())->mirror($this->depots[$url], $destination);
    }

    public function commit(string $directory): string
    {
        return str_repeat('a', 40);
    }
}
