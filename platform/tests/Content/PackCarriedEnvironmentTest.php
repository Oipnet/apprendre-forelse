<?php

namespace App\Tests\Content;

use App\Content\ContentException;
use App\Content\EnvironmentRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * L'environnement que le pack porte lui-même : `<pack>/environments/<id>/`.
 *
 * Un décor appartient au contenu qui le met en scène. Il se déplace avec son pack, se versionne avec
 * lui, et n'a ni dépôt ni déclaration à tenir à jour — le moteur le trouve du seul fait que le pack
 * est monté.
 */
final class PackCarriedEnvironmentTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../..';
    private string $tmp;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->tmp = sys_get_temp_dir().'/pack-porte-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->tmp);
    }

    public function testUnPackApporteSonEnvironnement(): void
    {
        $this->pack('houblon', ['ma-boutique' => "id: ma-boutique\nphp: '8.4'\ntitle: Ma boutique\n"]);
        $registry = $this->registry();

        $this->assertTrue($registry->has('ma-boutique'), 'Monté avec le pack, donc disponible.');
        $this->assertSame('Ma boutique', $registry->get('ma-boutique')->title);
        $this->assertSame(['ma-boutique'], array_keys($registry->carried()));
        // Et il reste distinct de ceux du moteur, qui sont toujours là.
        $this->assertTrue($registry->has('symfony-8'));
        $this->assertSame([], array_intersect(['symfony-8'], array_keys($registry->carried())));
    }

    /** Le cas qui justifie tout : « le Symfony complet, plus mes trois entités ». */
    public function testUnEnvironnementDePackPeutProlongerUnEnvironnementDuMoteur(): void
    {
        $this->pack('houblon', ['ma-boutique' => "id: ma-boutique\nextends: symfony-8\n"]);
        $this->filesystem->dumpFile($this->tmp.'/houblon/environments/ma-boutique/src/Controller/BoutiqueController.php', '<?php // à moi');

        $environnement = $this->registry()->get('ma-boutique');

        $this->assertTrue($environnement->isComposed());
        $this->assertNotNull($environnement->file('src/Kernel.php'), 'Le squelette vient du moteur.');
        $this->assertNotNull($environnement->file('src/Controller/BoutiqueController.php'));
        $this->assertSame('8.4', $environnement->phpVersion, 'La clé « php » s\'hérite.');
    }

    /** Un pack ne s'approprie pas « symfony-8 » en silence : le chargement s'arrête et nomme les deux. */
    public function testUnPackNePeutPasMasquerUnEnvironnementDuMoteur(): void
    {
        $this->pack('houblon', ['symfony-8' => "id: symfony-8\nphp: '8.4'\n"]);

        $this->expectException(ContentException::class);
        $this->expectExceptionMessage('déclaré deux fois');
        $this->registry()->has('symfony-8');
    }

    /** Deux packs ne peuvent pas apporter le même identifiant non plus. */
    public function testDeuxPacksNePeuventPasApporterLeMemeEnvironnement(): void
    {
        $this->pack('houblon', ['partage' => "id: partage\nphp: '8.4'\n"]);
        $this->pack('dragon', ['partage' => "id: partage\nphp: '8.4'\n"]);

        $this->expectException(ContentException::class);
        $this->expectExceptionMessage('déclaré deux fois');
        $this->registry()->all();
    }

    /** Sans dossier `environments/`, un pack ne change rien : c'est le cas de la plupart d'entre eux. */
    public function testUnPackSansEnvironnementNeChangeRien(): void
    {
        $this->filesystem->dumpFile($this->tmp.'/simple/pack.yaml', "id: simple\ntitle: Simple\n");
        $registry = $this->registry();

        $this->assertSame([], $registry->carried());
        $this->assertTrue($registry->has('symfony-8'));
    }

    /** Un dossier qui n'est pas un pack n'est pas fouillé, même s'il porte un `environments/`. */
    public function testUnDossierSansPackYamlNestPasFouille(): void
    {
        $this->filesystem->dumpFile($this->tmp.'/pas-un-pack/environments/intrus/environment.yaml', "id: intrus\nphp: '8.4'\n");

        $this->assertFalse($this->registry()->has('intrus'));
    }

    /** @param array<string, string> $environnements identifiant de dossier => contenu d'environment.yaml */
    private function pack(string $id, array $environnements): void
    {
        $this->filesystem->dumpFile($this->tmp.'/'.$id.'/pack.yaml', "id: {$id}\ntitle: {$id}\n");
        foreach ($environnements as $environnement => $manifeste) {
            $this->filesystem->dumpFile($this->tmp.'/'.$id.'/environments/'.$environnement.'/environment.yaml', $manifeste);
        }
    }

    private function registry(): EnvironmentRegistry
    {
        return new EnvironmentRegistry(self::ROOT.'/environments', packPaths: [$this->tmp]);
    }
}
