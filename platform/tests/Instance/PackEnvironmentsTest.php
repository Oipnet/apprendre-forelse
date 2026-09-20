<?php

namespace App\Tests\Instance;

use App\Content\ContentRepository;
use App\Content\EnvironmentRegistry;
use App\Instance\EnvironmentInstaller;
use App\Instance\InstalledEnvironments;
use App\Instance\PackEnvironments;
use App\Version;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Un pack déclare le décor dont il a besoin ; le moteur va le chercher s'il ne l'a pas.
 *
 * L'essentiel tient dans le « s'il ne l'a pas » : ce qui est déjà là n'est jamais retouché, et ce qui
 * n'est pas ce que le pack a demandé est dit plutôt qu'installé en silence.
 */
final class PackEnvironmentsTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../..';

    private string $tmp;
    private Filesystem $filesystem;
    private string|false $home;

    protected function setUp(): void
    {
        if (null === (new ExecutableFinder())->find('git')) {
            $this->markTestSkipped('git est introuvable.');
        }
        $this->filesystem = new Filesystem();
        $this->tmp = sys_get_temp_dir().'/pack-envs-'.bin2hex(random_bytes(6));
        $this->filesystem->mkdir([$this->tmp.'/installes', $this->tmp.'/packs', $this->tmp.'/maison']);
        // Un socle minuscule plutôt que symfony-8 : les assertions portent sur l'enchaînement
        // « pack → environnement manquant → dépôt », pas sur le squelette de Symfony, et empaqueter
        // 97 Mo de vendor/ coûterait une minute par test. Il ne déclare aucun composer.json, ce qui
        // couvre au passage la chaîne composée sans Composer.
        $this->filesystem->dumpFile($this->tmp.'/socle/base-test/environment.yaml', "id: base-test\nphp: '8.4'\ntitle: Socle\n");
        $this->filesystem->dumpFile($this->tmp.'/socle/base-test/src/Socle.php', '<?php // du socle');

        // Un « chez-soi » par test : la redirection de git posée plus bas ne doit pas lui survivre.
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

    /** Ce que le moteur livre déjà n'est pas réinstallé : le pack s'en sert tel quel. */
    public function testUnEnvironnementDejaLaNestPasReinstalle(): void
    {
        $this->pack('p', ['base-test' => 'https://exemple.test/depot.git']);
        $packEnvironments = $this->service();

        $etat = $packEnvironments->state();
        $this->assertCount(1, $etat);
        $this->assertSame(PackEnvironments::FOREIGN, $etat[0]['state']);
        $this->assertSame([], $packEnvironments->toInstall(), 'Rien à faire : cet environnement est déjà là.');
    }

    public function testUnEnvironnementManquantEstSignaleAvantDEtreInstalle(): void
    {
        $this->pack('p', ['ma-boutique' => 'https://exemple.test/depot.git']);
        $packEnvironments = $this->service();

        $this->assertSame(PackEnvironments::MISSING, $packEnvironments->state()[0]['state']);
        $aFaire = $packEnvironments->toInstall();
        $this->assertCount(1, $aFaire);
        $this->assertSame('ma-boutique', $aFaire[0]->id);
        $this->assertSame('p', $aFaire[0]->packId);
    }

    /** Le chemin complet : un pack arrive, son décor n'est pas là, il est cloné et empaqueté. */
    public function testUnEnvironnementManquantEstCloneEtInstalle(): void
    {
        $depot = $this->depot([
            'environment.yaml' => "id: ma-boutique\nextends: base-test\ntitle: Ma boutique\n",
            'src/Controller/BoutiqueController.php' => '<?php // à moi',
        ]);
        $this->pack('p', ['ma-boutique' => 'https://exemple.test/depot.git']);
        $this->redirige($depot);
        $environments = $this->registry();
        $packEnvironments = $this->service($environments);

        $resultat = $packEnvironments->synchronize();

        $this->assertSame(['ma-boutique'], $resultat['installed']);
        $this->assertSame([], $resultat['failed']);

        // Le moteur le charge, et il prolonge bien l'environnement déjà présent.
        $environments->reset();
        $environnement = $environments->get('ma-boutique');
        $this->assertTrue($environnement->isComposed());
        $this->assertNotNull($environnement->file('src/Socle.php'), 'Les fichiers viennent du socle.');
        $this->assertNotNull($environnement->file('src/Controller/BoutiqueController.php'));

        // Et l'archive servie au navigateur porte elle aussi la superposition, pas seulement le dépôt.
        $this->assertSame(
            ['src/Controller/BoutiqueController.php', 'src/Socle.php'],
            $this->contenuDe($this->tmp.'/installes/.artefacts/ma-boutique.zip'),
        );

        // Et il ne se réinstalle pas au passage suivant.
        $this->assertSame(PackEnvironments::PRESENT, $packEnvironments->state()[0]['state']);
        $this->assertSame([], $packEnvironments->toInstall());
    }

    /** Le dépôt se nomme lui-même : s'il ne porte pas le nom attendu, le pack ne trouverait rien. */
    public function testUnDepotQuiNePortePasLIdentifiantAttenduEstSignale(): void
    {
        $depot = $this->depot(['environment.yaml' => "id: autre-chose\nextends: base-test\n"]);
        $this->pack('p', ['ma-boutique' => 'https://exemple.test/depot.git']);
        $this->redirige($depot);
        $packEnvironments = $this->service();

        $resultat = $packEnvironments->synchronize();

        $this->assertSame([], $resultat['installed']);
        $this->assertArrayHasKey('ma-boutique', $resultat['failed']);
        $this->assertStringContainsString('fournit l\'environnement « autre-chose »', $resultat['failed']['ma-boutique']);
    }

    /** Un dépôt injoignable n'emporte pas les autres : ce qui peut s'installer s'installe. */
    public function testUnDepotInjoignableNempechePasLesAutres(): void
    {
        $depot = $this->depot(['environment.yaml' => "id: ma-boutique\nextends: base-test\n"]);
        $this->pack('p', [
            'ma-boutique' => 'https://exemple.test/depot.git',
            'introuvable' => 'https://github.invalid/absent.git',
        ]);
        $this->redirige($depot);
        $packEnvironments = $this->service();

        $resultat = $packEnvironments->synchronize();

        $this->assertSame(['ma-boutique'], $resultat['installed']);
        $this->assertArrayHasKey('introuvable', $resultat['failed']);
        $this->assertStringContainsString('Clonage', $resultat['failed']['introuvable']);
    }

    /**
     * @param array<string, string|array{0: string, 1: string}> $environnements identifiant => adresse,
     *                                                                         ou [adresse, sous-dossier]
     */
    private function pack(string $id, array $environnements): void
    {
        $lignes = ["id: {$id}", "title: {$id}", 'environments:'];
        foreach ($environnements as $environnement => $source) {
            [$depot, $dossier] = \is_array($source) ? $source : [$source, ''];
            $lignes[] = "  - id: {$environnement}";
            $lignes[] = "    depot: '{$depot}'";
            if ('' !== $dossier) {
                $lignes[] = "    dossier: '{$dossier}'";
            }
        }
        $this->filesystem->dumpFile($this->tmp.'/packs/'.$id.'/pack.yaml', implode("\n", $lignes)."\n");
    }

    /** Un dépôt peut porter plusieurs environnements : « dossier: » dit lequel installer. */
    public function testUnDepotPeutPorterPlusieursEnvironnements(): void
    {
        $depot = $this->depot([
            'symfony/environment.yaml' => "id: mon-symfony\nextends: base-test\n",
            'symfony/src/Mon.php' => '<?php // symfony',
            'laravel/environment.yaml' => "id: mon-laravel\nextends: base-test\n",
            'laravel/src/Autre.php' => '<?php // laravel',
        ]);
        $this->pack('p', [
            'mon-symfony' => ['https://exemple.test/depot.git', 'symfony'],
            'mon-laravel' => ['https://exemple.test/depot.git', 'laravel'],
        ]);
        $this->redirige($depot);
        $environments = $this->registry();

        $resultat = $this->service($environments)->synchronize();

        $this->assertSame([], $resultat['failed']);
        $this->assertEqualsCanonicalizing(['mon-symfony', 'mon-laravel'], $resultat['installed']);

        // Chacun n'a reçu que son dossier, pas le dépôt entier.
        $environments->reset();
        $this->assertNotNull($environments->get('mon-symfony')->file('src/Mon.php'));
        $this->assertNull($environments->get('mon-symfony')->file('src/Autre.php'), 'Le voisin n\'est pas venu avec.');
        $this->assertNotNull($environments->get('mon-laravel')->file('src/Autre.php'));
    }

    /**
     * Un environnement peut en prolonger un autre qui s'installe dans la même passe.
     *
     * L'ordre est alphabétique et « aa-appli » passe avant sa base « zz-socle » : la première tentative
     * échoue forcément. C'est le cas que la seconde passe existe pour rattraper — et on ne peut pas
     * l'éviter en triant, puisque « extends: » n'est lisible qu'une fois le dépôt cloné.
     */
    public function testUnEnvironnementQuiProlongeUnAutreDeLaMemePasseFinitParSInstaller(): void
    {
        $depot = $this->depot([
            'appli/environment.yaml' => "id: aa-appli\nextends: zz-socle\n",
            'appli/src/Appli.php' => '<?php // appli',
            'socle/environment.yaml' => "id: zz-socle\nphp: '8.4'\n",
            'socle/src/Base.php' => '<?php // base',
        ]);
        $this->pack('p', [
            'aa-appli' => ['https://exemple.test/depot.git', 'appli'],
            'zz-socle' => ['https://exemple.test/depot.git', 'socle'],
        ]);
        $this->redirige($depot);
        $environments = $this->registry();

        $resultat = $this->service($environments)->synchronize();

        $this->assertSame([], $resultat['failed']);
        $this->assertSame(['zz-socle', 'aa-appli'], $resultat['installed'], 'La base d\'abord, l\'enfant à la passe suivante.');

        $environments->reset();
        $this->assertNotNull($environments->get('aa-appli')->file('src/Base.php'), 'L\'enfant a bien hérité du socle.');
    }

    /**
     * Une base introuvable arrête l'empaquetage, au lieu de livrer une archive amputée.
     *
     * Le cas mérite son test : la chaîne se résolvait dans une fonction appelée depuis une liste
     * « && », où bash désactive « set -e ». L'échec ne faisait qu'écrire sur stderr et l'archive
     * partait sans sa base, code de sortie 0 — un Symfony sans Symfony dedans.
     */
    public function testUneBaseIntrouvableArreteLEmpaquetage(): void
    {
        $depot = $this->depot(['environment.yaml' => "id: ma-boutique\nextends: base-qui-nexiste-pas\n"]);
        $this->pack('p', ['ma-boutique' => 'https://exemple.test/depot.git']);
        $this->redirige($depot);

        $resultat = $this->service()->synchronize();

        $this->assertSame([], $resultat['installed']);
        $this->assertStringContainsString('base-qui-nexiste-pas', $resultat['failed']['ma-boutique']);
        $this->assertFileDoesNotExist($this->tmp.'/installes/.artefacts/ma-boutique.zip');
    }

    /** Un sous-dossier absent du dépôt est une erreur nommée, pas un environnement vide. */
    public function testUnSousDossierAbsentEstSignale(): void
    {
        $depot = $this->depot(['environment.yaml' => "id: ma-boutique\nextends: base-test\n"]);
        $this->pack('p', ['ma-boutique' => ['https://exemple.test/depot.git', 'nulle-part']]);
        $this->redirige($depot);

        $resultat = $this->service()->synchronize();

        $this->assertSame([], $resultat['installed']);
        $this->assertStringContainsString('ne contient pas de dossier « nulle-part »', $resultat['failed']['ma-boutique']);
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

    /** Fait pointer « https://exemple.test/depot.git » sur un dépôt du disque, via la configuration de git. */
    private function redirige(string $depot): void
    {
        (new Process(['git', 'config', '--global', 'url.'.$depot.'.insteadOf', 'https://exemple.test/depot.git']))->mustRun();
        (new Process(['git', 'config', '--global', 'protocol.file.allow', 'always']))->mustRun();
    }

    /** Les fichiers d'une archive, triés : de quoi vérifier ce que l'apprenant recevrait. */
    private function contenuDe(string $archive): array
    {
        $zip = new \ZipArchive();
        $this->assertTrue(true === $zip->open($archive), 'Archive illisible : '.$archive);
        $fichiers = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $nom = (string) $zip->getNameIndex($i);
            if (!str_ends_with($nom, '/')) {
                $fichiers[] = $nom;
            }
        }
        $zip->close();
        sort($fichiers);

        return $fichiers;
    }

    private function registry(): EnvironmentRegistry
    {
        return new EnvironmentRegistry([$this->tmp.'/socle', $this->tmp.'/installes']);
    }

    private function service(?EnvironmentRegistry $environments = null): PackEnvironments
    {
        $environments ??= $this->registry();
        $installed = new InstalledEnvironments($this->tmp.'/installes');
        $content = new ContentRepository([$this->tmp.'/packs'], $environments, new Version(self::ROOT.'/VERSION'));

        return new PackEnvironments($content, $environments, $installed, new EnvironmentInstaller(
            $installed,
            $environments,
            $this->tmp.'/socle,'.$this->tmp.'/installes',
            self::ROOT.'/environments/bin/build-env.sh',
        ));
    }
}
