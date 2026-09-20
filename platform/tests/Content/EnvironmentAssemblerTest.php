<?php

namespace App\Tests\Content;

use App\Content\EnvironmentAssembler;
use App\Content\EnvironmentRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * La superposition d'une chaîne d'environnements : ce que content:check donne à jouer à l'exercice.
 *
 * Le test qui compte est celui des dates : sans « override », Symfony ne remplace un fichier que s'il
 * est plus récent, et les dates d'un dépôt cloné sont toutes voisines — l'enfant l'emporterait une fois
 * sur deux, selon la machine. Un bogue silencieux, dépendant de l'environnement, et que seule une
 * assertion sur des dates inversées attrape.
 */
final class EnvironmentAssemblerTest extends TestCase
{
    private string $racine;
    private string $workdir;

    protected function setUp(): void
    {
        $this->racine = sys_get_temp_dir().'/envs-'.bin2hex(random_bytes(6));
        $this->workdir = sys_get_temp_dir().'/work-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove([$this->racine, $this->workdir]);
    }

    public function testLEnfantLEmporteMemeQuandSonFichierEstPlusAncien(): void
    {
        $this->ecrire([
            'base/environment.yaml' => "id: base\nphp: '8.4'\n",
            'base/composer.json' => '{}',
            'base/src/Remplace.php' => '<?php // base',
            'base/src/Commun.php' => '<?php // base',
            'enfant/environment.yaml' => "id: enfant\nextends: base\n",
            'enfant/src/Remplace.php' => '<?php // enfant',
            'enfant/src/Ajoute.php' => '<?php // enfant',
        ]);
        // Le fichier de l'enfant est daté d'hier, celui de la base de maintenant : il doit gagner quand même.
        touch($this->racine.'/enfant/src/Remplace.php', time() - 86400);
        touch($this->racine.'/base/src/Remplace.php', time());

        $this->assembler()->assemble((new EnvironmentRegistry($this->racine))->get('enfant'), $this->workdir);

        $this->assertSame('<?php // enfant', file_get_contents($this->workdir.'/src/Remplace.php'));
        $this->assertSame('<?php // base', file_get_contents($this->workdir.'/src/Commun.php'));
        $this->assertFileExists($this->workdir.'/src/Ajoute.php');
    }

    /** vendor/ vient du seul dossier qui installe : ce que l'enfant a retiré ne doit pas traîner. */
    public function testLeVendorNeSeSuperposePas(): void
    {
        $this->ecrire([
            'base/environment.yaml' => "id: base\nphp: '8.4'\n",
            'base/composer.json' => '{"require":{"a/a":"*"}}',
            'base/vendor/a/a/A.php' => '<?php // a',
            'enfant/environment.yaml' => "id: enfant\nextends: base\n",
            'enfant/composer.json' => '{"require":{"b/b":"*"}}',
            'enfant/vendor/b/b/B.php' => '<?php // b',
        ]);

        $this->assembler()->assemble((new EnvironmentRegistry($this->racine))->get('enfant'), $this->workdir);

        $this->assertFileExists($this->workdir.'/vendor/b/b/B.php');
        $this->assertFileDoesNotExist($this->workdir.'/vendor/a/a/A.php', 'Le paquet d\'une base que l\'enfant n\'a plus ne doit pas rester.');
    }

    /** Un enfant sans composer.json joue avec les dépendances de sa base. */
    public function testUnEnfantSansComposerRecoitLeVendorDeSaBase(): void
    {
        $this->ecrire([
            'base/environment.yaml' => "id: base\nphp: '8.4'\n",
            'base/composer.json' => '{}',
            'base/vendor/a/a/A.php' => '<?php // a',
            'enfant/environment.yaml' => "id: enfant\nextends: base\n",
            'enfant/src/Ajoute.php' => '<?php // enfant',
        ]);

        $this->assembler()->assemble((new EnvironmentRegistry($this->racine))->get('enfant'), $this->workdir);

        $this->assertFileExists($this->workdir.'/vendor/a/a/A.php');
        $this->assertFileExists($this->workdir.'/src/Ajoute.php');
    }

    /** Les caches et les artefacts de construction ne suivent pas dans le projet joué. */
    public function testLesCachesNeSontPasRecopies(): void
    {
        $this->ecrire([
            'base/environment.yaml' => "id: base\nphp: '8.4'\n",
            'base/composer.json' => '{}',
            'base/var/cache/truc.php' => '<?php // cache',
            'base/src/A.php' => '<?php',
        ]);

        $this->assembler()->assemble((new EnvironmentRegistry($this->racine))->get('base'), $this->workdir);

        $this->assertFileExists($this->workdir.'/src/A.php');
        $this->assertFileDoesNotExist($this->workdir.'/var/cache/truc.php');
    }

    private function assembler(): EnvironmentAssembler
    {
        return new EnvironmentAssembler();
    }

    /** @param array<string, string> $fichiers */
    private function ecrire(array $fichiers): void
    {
        $filesystem = new Filesystem();
        foreach ($fichiers as $chemin => $contenu) {
            $filesystem->dumpFile($this->racine.'/'.$chemin, $contenu);
        }
    }
}
