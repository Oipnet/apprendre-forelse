<?php

namespace App\Tests\Content;

use App\Content\ContentException;
use App\Content\EnvironmentRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class EnvironmentRegistryTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../..';

    private ?string $tmp = null;

    protected function tearDown(): void
    {
        if (null !== $this->tmp) {
            (new Filesystem())->remove($this->tmp);
            $this->tmp = null;
        }
        parent::tearDown();
    }

    public function testLEnvironnementNuxtSePasseDePhp(): void
    {
        $environment = (new EnvironmentRegistry(self::ROOT.'/environments'))->get('nuxt-4');

        $this->assertSame('nuxt', $environment->framework->id);
        // Le profil dit comment ce projet se joue : ses tests sont des tests Vitest, pas PHPUnit.
        $this->assertFalse($environment->framework->runsPhpunit());
        $this->assertSame('', $environment->phpVersion);
        $this->assertSame([], $environment->cacheDirs);
        $this->assertSame('envs/nuxt-4.zip', $environment->archivePath());
    }

    /** Les caches par défaut viennent du profil ; « cache: » dans environment.yaml a le dernier mot. */
    public function testLesCachesViennentDuProfilSaufMentionContraire(): void
    {
        $environments = new EnvironmentRegistry(self::ROOT.'/environments');

        $this->assertSame(['var/cache'], $environments->get('symfony-8')->cacheDirs);
        $this->assertSame(['storage/framework/views'], $environments->get('laravel-13')->cacheDirs);
        // environments/docker/environment.yaml déclare « cache: [] ».
        $this->assertSame([], $environments->get('docker')->cacheDirs);
    }

    /**
     * Un environnement peut en prolonger un autre : il ne porte alors que sa différence, et le projet
     * joué est la superposition de la chaîne. C'est ce qui évite de recopier un squelette entier.
     */
    public function testUnEnvironnementProlongeSaBaseEtNePorteQueSaDifference(): void
    {
        $environnements = new EnvironmentRegistry(self::ROOT.'/environments');
        $app = $environnements->get('symfony-8-app');

        $this->assertTrue($app->isComposed());
        $this->assertSame(
            [self::ROOT.'/environments/symfony-8', self::ROOT.'/environments/symfony-8-app'],
            $app->directories,
            'De la base à l\'enfant : un fichier de l\'enfant l\'emporte.',
        );
        // Hérité de symfony-8, que symfony-8-app ne redéclare pas.
        $this->assertSame(\realpath(self::ROOT.'/environments/symfony-8/src/Kernel.php'), \realpath((string) $app->file('src/Kernel.php')));
        // Redéclaré par symfony-8-app.
        $this->assertSame(\realpath(self::ROOT.'/environments/symfony-8-app/composer.json'), \realpath((string) $app->file('composer.json')));
        // Propre à symfony-8-app.
        $this->assertNotNull($app->file('config/packages/security.yaml'));
        // Le titre ne s'hérite pas ; le reste, si.
        $this->assertStringContainsString('Messenger', $app->title);
        $this->assertSame('8.4', $app->phpVersion);
        $this->assertSame('symfony', $app->framework->id);
        $this->assertSame(['var/cache'], $app->cacheDirs);
    }

    /** vendor/ ne se superpose pas : il vient du seul dossier qui déclare un composer.json. */
    public function testLeDossierDInstallationEstLePlusParticulierQuiDeclareComposer(): void
    {
        $environnements = new EnvironmentRegistry(self::ROOT.'/environments');

        $this->assertSame(
            \realpath(self::ROOT.'/environments/symfony-8-app'),
            \realpath((string) $environnements->get('symfony-8-app')->composerDirectory()),
        );
        $this->assertSame(
            \realpath(self::ROOT.'/environments/symfony-8'),
            \realpath((string) $environnements->get('symfony-8')->composerDirectory()),
        );
    }

    /** Un enfant qui n'ajoute aucune dépendance hérite du composer.json — donc du vendor/ — de sa base. */
    public function testUnEnfantSansComposerHeriteDeCeluiDeSaBase(): void
    {
        $this->tmp = $this->environnements([
            'base' => "id: base\nphp: '8.4'\ncache: [var/cache]\n",
            'base/composer.json' => '{}',
            'base/src/Commun.php' => '<?php // base',
            'base/src/Remplace.php' => '<?php // base',
            'enfant' => "id: enfant\nextends: base\ntitle: L'enfant\n",
            'enfant/src/Remplace.php' => '<?php // enfant',
            'enfant/src/Ajoute.php' => '<?php // enfant',
        ]);
        $enfant = (new EnvironmentRegistry($this->tmp))->get('enfant');

        $this->assertSame($this->tmp.'/base', $enfant->composerDirectory());
        $this->assertSame('8.4', $enfant->phpVersion, 'La version de PHP s\'hérite.');
        $this->assertSame(['var/cache'], $enfant->cacheDirs, 'Les caches aussi.');
        $this->assertSame("L'enfant", $enfant->title);
        $this->assertSame($this->tmp.'/enfant/src/Remplace.php', $enfant->file('src/Remplace.php'));
        $this->assertSame($this->tmp.'/base/src/Commun.php', $enfant->file('src/Commun.php'));
        $sources = $enfant->filesIn('src');
        ksort($sources);
        $this->assertSame(
            ['Ajoute.php', 'Commun.php', 'Remplace.php'],
            array_keys($sources),
            'Les fichiers d\'un dossier se superposent au lieu de se remplacer en bloc.',
        );
        $this->assertSame($this->tmp.'/enfant/src/Remplace.php', $sources['Remplace.php']);
    }

    public function testUneChaineQuiTourneEnRondEstSignalee(): void
    {
        $this->tmp = $this->environnements([
            'a' => "id: a\nphp: '8.4'\nextends: b\n",
            'b' => "id: b\nphp: '8.4'\nextends: a\n",
        ]);

        $this->expectException(ContentException::class);
        $this->expectExceptionMessage('tourne en rond (a → b → a)');
        (new EnvironmentRegistry($this->tmp))->get('a');
    }

    public function testUneBaseIntrouvableDitQuiLaProlonge(): void
    {
        $this->tmp = $this->environnements(['enfant' => "id: enfant\nphp: '8.4'\nextends: disparu\n"]);

        $this->expectException(ContentException::class);
        $this->expectExceptionMessage('« disparu » introuvable, prolongé par « enfant »');
        (new EnvironmentRegistry($this->tmp))->get('enfant');
    }

    /**
     * @param array<string, string> $fichiers « <env> » pour son environment.yaml, « <env>/chemin » pour un fichier
     */
    private function environnements(array $fichiers): string
    {
        $racine = sys_get_temp_dir().'/envs-'.bin2hex(random_bytes(6));
        $filesystem = new Filesystem();
        foreach ($fichiers as $chemin => $contenu) {
            $filesystem->dumpFile($racine.'/'.(str_contains($chemin, '/') ? $chemin : $chemin.'/environment.yaml'), $contenu);
        }

        return $racine;
    }

    public function testUnFrameworkInconnuEstSignaleAvecLaListeDeCeuxQuiExistent(): void
    {
        $tmp = sys_get_temp_dir().'/env-'.bin2hex(random_bytes(6));
        mkdir($tmp.'/maison', recursive: true);
        file_put_contents($tmp.'/maison/environment.yaml', "id: maison\nphp: '8.4'\nframework: rails\n");

        try {
            $this->expectException(\App\Content\ContentException::class);
            $this->expectExceptionMessage('framework « rails » inconnu (symfony, laravel, docker, nuxt)');
            (new EnvironmentRegistry($tmp))->get('maison');
        } finally {
            @unlink($tmp.'/maison/environment.yaml');
            @rmdir($tmp.'/maison');
            @rmdir($tmp);
        }
    }
}
