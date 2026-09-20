<?php

namespace App\Tests\Content;

use App\Content\ContentException;
use App\Content\ContentRepository;
use App\Content\EnvironmentRegistry;
use App\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * La clé « environments » de pack.yaml : un pack dit de quel décor il a besoin, et où le prendre.
 *
 * Ce qui est vérifié au chargement, c'est la forme — pas la présence. Un dépôt injoignable ne doit pas
 * éteindre la plateforme ; c'est PackEnvironments qui s'occupe de ce qui manque.
 */
final class PackEnvironmentTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../..';
    private string $tmp;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->tmp = sys_get_temp_dir().'/pack-env-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->tmp);
    }

    public function testUnPackDeclareSesEnvironnementsEtOuLesPrendre(): void
    {
        $repository = $this->repository(['p' => <<<'YAML'
            id: p
            title: P
            environments:
              - id: ma-boutique
                depot: https://exemple.test/boutique.git
                ref: v1.2.0
              - id: mon-api
                depot: https://exemple.test/api.git
            YAML]);

        $pack = $repository->packs()['p'];
        $this->assertCount(2, $pack->environments);
        $this->assertSame('ma-boutique', $pack->environments[0]->id);
        $this->assertSame('v1.2.0', $pack->environments[0]->ref);
        $this->assertSame('p', $pack->environments[0]->packId);
        $this->assertSame('', $pack->environments[1]->ref, 'Sans « ref », la branche par défaut du dépôt.');

        $wanted = $repository->packEnvironments();
        $this->assertSame(['ma-boutique', 'mon-api'], array_keys($wanted));
        $this->assertSame('https://exemple.test/boutique.git (v1.2.0)', $wanted['ma-boutique']->describeSource());
    }

    /** Sans la clé, rien ne change : les packs existants ne déclarent aucun environnement. */
    public function testUnPackSansLaCleNeDemandeRien(): void
    {
        $repository = $this->repository(['p' => "id: p\ntitle: P"]);

        $this->assertSame([], $repository->packs()['p']->environments);
        $this->assertSame([], $repository->packEnvironments());
    }

    /** Deux packs peuvent vouloir le même décor — c'est le but — s'ils le demandent au même endroit. */
    public function testDeuxPacksPeuventDemanderLeMemeEnvironnement(): void
    {
        $repository = $this->repository([
            'p' => "id: p\ntitle: P\nenvironments: [{id: partage, depot: 'https://exemple.test/d.git'}]",
            'q' => "id: q\ntitle: Q\nenvironments: [{id: partage, depot: 'https://exemple.test/d.git'}]",
        ]);

        $this->assertSame(['partage'], array_keys($repository->packEnvironments()));
    }

    public function testDeuxAdressesPourUnSeulIdentifiantSontRefusees(): void
    {
        $repository = $this->repository([
            'p' => "id: p\ntitle: P\nenvironments: [{id: partage, depot: 'https://exemple.test/un.git'}]",
            'q' => "id: q\ntitle: Q\nenvironments: [{id: partage, depot: 'https://exemple.test/deux.git'}]",
        ]);

        $this->expectException(ContentException::class);
        $this->expectExceptionMessage('demandé à deux endroits différents');
        $repository->packEnvironments();
    }

    /** @return iterable<string, array{string, string}> */
    public static function declarationsInvalides(): iterable
    {
        yield 'pas une liste' => ['environments: ma-boutique', 'doit être une liste'];
        yield 'une entrée qui n\'est pas une table' => ['environments: [ma-boutique]', 'chaque entrée est une table'];
        yield 'sans dépôt' => ['environments: [{id: ma-boutique}]', '« id » et « depot » sont obligatoires'];
        yield 'sans identifiant' => ["environments: [{depot: 'https://exemple.test/d.git'}]", '« id » et « depot » sont obligatoires'];
        yield 'un identifiant qui remonte' => ["environments: [{id: '../../etc', depot: 'https://exemple.test/d.git'}]", 'invalide'];
        yield 'une adresse locale' => ['environments: [{id: ma-boutique, depot: "file:///etc/passwd"}]', 'doit commencer par « https:// »'];
        yield 'une clé du serveur' => ['environments: [{id: ma-boutique, depot: "git@github.com:o/d.git"}]', 'doit commencer par « https:// »'];
    }

    #[DataProvider('declarationsInvalides')]
    public function testUneDeclarationMalFormeeEstRefuseeAuChargement(string $cle, string $message): void
    {
        $this->expectException(ContentException::class);
        $this->expectExceptionMessage($message);
        $this->repository(['p' => "id: p\ntitle: P\n".$cle])->packs();
    }

    /** @param array<string, string> $packs identifiant de dossier => contenu de pack.yaml */
    private function repository(array $packs): ContentRepository
    {
        foreach ($packs as $directory => $manifest) {
            $this->filesystem->dumpFile($this->tmp.'/'.$directory.'/pack.yaml', $manifest);
        }

        return new ContentRepository([$this->tmp], new EnvironmentRegistry(self::ROOT.'/environments'), new Version(self::ROOT.'/VERSION'));
    }
}
