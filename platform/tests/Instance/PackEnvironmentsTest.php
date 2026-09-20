<?php

namespace App\Tests\Instance;

use App\Content\EnvironmentRegistry;
use App\Instance\EnvironmentArtifacts;
use App\Instance\EnvironmentInstaller;
use App\Instance\InstalledEnvironments;
use App\Instance\PackEnvironments;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * L'empaquetage des environnements que les packs portent.
 *
 * Le décor arrive avec son pack : il n'y a rien à cloner ni à déclarer. Ce qui se vérifie ici, c'est
 * qu'on lui fabrique bien son archive — la superposition comprise — et que les ratés se disent.
 */
final class PackEnvironmentsTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../..';

    private string $tmp;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->tmp = sys_get_temp_dir().'/pack-envs-'.bin2hex(random_bytes(6));
        $this->filesystem->mkdir([$this->tmp.'/installes', $this->tmp.'/packs']);
        // Un socle minuscule plutôt que symfony-8 : les assertions portent sur l'empaquetage, pas sur
        // le squelette de Symfony, et zipper 97 Mo de vendor/ coûterait une minute par test. Il ne
        // déclare aucun composer.json, ce qui couvre au passage la chaîne composée sans Composer.
        $this->filesystem->dumpFile($this->tmp.'/socle/base-test/environment.yaml', "id: base-test\nphp: '8.4'\ntitle: Socle\n");
        $this->filesystem->dumpFile($this->tmp.'/socle/base-test/src/Base.php', '<?php // du socle');
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->tmp);
    }

    /** Un pack sans dossier `environments/` ne demande rien : c'est le cas de la plupart. */
    public function testUnPackSansEnvironnementNaRienAEmpaqueter(): void
    {
        $this->pack('simple');

        $this->assertSame([], $this->service()->state());
        $this->assertSame([], $this->service()->toBuild());
    }

    /** Le chemin ordinaire : le pack apporte son décor, le moteur lui fabrique son archive. */
    public function testUnEnvironnementPorteParUnPackEstEmpaquete(): void
    {
        $this->pack('houblon', ['ma-boutique' => "id: ma-boutique\nextends: base-test\n"]);
        $this->filesystem->dumpFile($this->tmp.'/packs/houblon/environments/ma-boutique/src/Boutique.php', '<?php // à moi');
        $packEnvironments = $this->service();

        $this->assertSame(['ma-boutique'], $packEnvironments->toBuild(), 'Présent, mais pas encore empaqueté.');
        $this->assertFalse($packEnvironments->state()[0]['built']);

        $resultat = $packEnvironments->synchronize();

        $this->assertSame([], $resultat['failed']);
        $this->assertSame(['ma-boutique'], $resultat['built']);
        // L'archive porte la superposition : les fichiers du pack et ceux du socle qu'il prolonge.
        $this->assertSame(
            ['src/Base.php', 'src/Boutique.php'],
            $this->contenuDe($this->tmp.'/installes/.artefacts/ma-boutique.zip'),
        );
        $this->assertFileExists($this->tmp.'/installes/.artefacts/ma-boutique.completion.json');
        $this->assertSame([], $packEnvironments->toBuild(), 'Empaqueté : plus rien à faire.');
    }

    /**
     * Un environnement peut en prolonger un autre du même lot, sans que l'ordre compte.
     *
     * « aa-appli » est empaqueté avant sa base « zz-socle » — et cela marche, parce qu'`extends:` se
     * résout entre **dossiers**, tous arrivés avec le pack, et non entre archives. C'est ce qui
     * dispense d'ordonner quoi que ce soit.
     */
    public function testUnEnvironnementQuiProlongeUnAutreDuMemeLotSEmpaqueteQuelQueSoitLOrdre(): void
    {
        $this->pack('houblon', [
            'aa-appli' => "id: aa-appli\nextends: zz-socle\n",
            'zz-socle' => "id: zz-socle\nphp: '8.4'\n",
        ]);
        $this->filesystem->dumpFile($this->tmp.'/packs/houblon/environments/zz-socle/src/Base.php', '<?php // base');
        $this->filesystem->dumpFile($this->tmp.'/packs/houblon/environments/aa-appli/src/Appli.php', '<?php // appli');

        $resultat = $this->service()->synchronize();

        $this->assertSame([], $resultat['failed']);
        $this->assertSame(['aa-appli', 'zz-socle'], $resultat['built'], 'Ordre alphabétique : l\'enfant avant sa base, sans que cela gêne.');
        $this->assertSame(
            ['src/Appli.php', 'src/Base.php'],
            $this->contenuDe($this->tmp.'/installes/.artefacts/aa-appli.zip'),
        );
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
        $this->pack('houblon', ['ma-boutique' => "id: ma-boutique\nextends: base-qui-nexiste-pas\n"]);

        $resultat = $this->service()->synchronize();

        $this->assertSame([], $resultat['built']);
        $this->assertStringContainsString('base-qui-nexiste-pas', $resultat['failed']['ma-boutique']);
        $this->assertFileDoesNotExist($this->tmp.'/installes/.artefacts/ma-boutique.zip');
    }

    /** Un décor qui ne compile pas n'emporte pas les autres. */
    public function testUnEchecNempechePasLesAutres(): void
    {
        $this->pack('houblon', [
            'bon' => "id: bon\nphp: '8.4'\n",
            'casse' => "id: casse\nextends: nulle-part\n",
        ]);
        $this->filesystem->dumpFile($this->tmp.'/packs/houblon/environments/bon/src/Bon.php', '<?php');

        $resultat = $this->service()->synchronize();

        $this->assertSame(['bon'], $resultat['built']);
        $this->assertArrayHasKey('casse', $resultat['failed']);
    }

    /** Sans dossier où déposer les archives, l'empaquetage est refusé et le dit. */
    public function testSansDossierDArchivesLEmpaquetageEstRefuse(): void
    {
        $this->pack('houblon', ['ma-boutique' => "id: ma-boutique\nphp: '8.4'\n"]);
        $installed = new InstalledEnvironments($this->tmp.'/nexiste-pas');

        $resultat = $this->service(installed: $installed)->synchronize();

        $this->assertSame([], $resultat['built']);
        $this->assertStringContainsString('n\'existe pas ou n\'est pas écrivable', $resultat['failed']['ma-boutique']);
    }

    /** @param array<string, string> $environnements identifiant => contenu d'environment.yaml */
    private function pack(string $id, array $environnements = []): void
    {
        $this->filesystem->dumpFile($this->tmp.'/packs/'.$id.'/pack.yaml', "id: {$id}\ntitle: {$id}\n");
        foreach ($environnements as $environnement => $manifeste) {
            $this->filesystem->dumpFile($this->tmp.'/packs/'.$id.'/environments/'.$environnement.'/environment.yaml', $manifeste);
        }
    }

    /**
     * Les fichiers d'une archive, triés : de quoi vérifier ce que l'apprenant recevrait.
     *
     * @return list<string>
     */
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

    private function service(?InstalledEnvironments $installed = null): PackEnvironments
    {
        $environments = new EnvironmentRegistry([$this->tmp.'/socle'], packPaths: [$this->tmp.'/packs']);
        $installed ??= new InstalledEnvironments($this->tmp.'/installes');
        $installer = new EnvironmentInstaller($installed, $environments, self::ROOT.'/environments/bin/build-env.sh');

        return new PackEnvironments($environments, $installer, new EnvironmentArtifacts($this->tmp.'/rien-de-public', $installed));
    }
}
