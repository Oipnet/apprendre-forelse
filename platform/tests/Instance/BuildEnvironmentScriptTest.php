<?php

namespace App\Tests\Instance;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * environments/bin/build-env.sh refuse d'empaqueter un environnement dont un paquet a été installé par clone git.
 *
 * Quand GitHub refuse les archives des paquets (403, limite des requêtes anonymes), Composer se rabat sans rien dire
 * sur un « git clone » : chaque paquet arrive avec son .git, ses tests et sa documentation, et l'archive que chaque
 * apprenant télécharge passe de quelques dizaines de Mo à plusieurs Go. Le clone est simulé ici par un
 * vendor/acme/clone/.git déposé à la main, qu'un composer install sans dépendance laisse en place.
 */
final class BuildEnvironmentScriptTest extends TestCase
{
    private const string SCRIPT = __DIR__.'/../../../environments/bin/build-env.sh';

    private string $tmp;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        foreach (['composer', 'zip'] as $outil) {
            if (null === (new ExecutableFinder())->find($outil)) {
                $this->markTestSkipped($outil.' est introuvable.');
            }
        }
        $this->filesystem = new Filesystem();
        $this->tmp = sys_get_temp_dir().'/build-env-'.bin2hex(random_bytes(6));
        // Aucune dépendance et pas de Packagist : composer install n'a rien à télécharger.
        $this->filesystem->dumpFile($this->tmp.'/envs/mini/environment.yaml', "id: mini\nphp: '8.4'\ntitle: Mini\n");
        $this->filesystem->dumpFile($this->tmp.'/envs/mini/composer.json', json_encode(['name' => 'test/mini', 'require' => new \stdClass(), 'repositories' => [['packagist.org' => false]]], \JSON_THROW_ON_ERROR));
        $this->filesystem->dumpFile($this->tmp.'/envs/mini/src/Kernel.php', '<?php // le projet');
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->tmp);
    }

    private function build(): Process
    {
        $process = new Process(['bash', self::SCRIPT, 'mini', $this->tmp.'/sortie'], env: ['ENVIRONMENTS_PATH' => $this->tmp.'/envs', 'COMPOSER_NO_AUDIT' => '1']);
        $process->setTimeout(120);
        $process->run();

        return $process;
    }

    public function testUnEnvironnementInstalleDepuisLesArchivesEstEmpaquete(): void
    {
        $build = $this->build();

        $this->assertTrue($build->isSuccessful(), $build->getErrorOutput());
        $this->assertFileExists($this->tmp.'/sortie/mini.zip');
    }

    public function testUnPaquetInstalleParCloneArreteLEmpaquetage(): void
    {
        $this->filesystem->dumpFile($this->tmp.'/envs/mini/vendor/acme/clone/.git/HEAD', "ref: refs/heads/main\n");
        $this->filesystem->dumpFile($this->tmp.'/envs/mini/vendor/acme/archive/src/Archive.php', '<?php // installé depuis son archive');

        $build = $this->build();

        $this->assertSame(1, $build->getExitCode());
        $erreur = $build->getErrorOutput();
        $this->assertStringContainsString('installés par clone git', $erreur);
        $this->assertStringContainsString('  - acme/clone', $erreur);
        $this->assertStringNotContainsString('acme/archive', $erreur, 'Seuls les paquets clonés sont listés.');
        $this->assertStringContainsString('COMPOSER_AUTH', $erreur, 'Le message dit comment s\'en sortir.');
        $this->assertFileDoesNotExist($this->tmp.'/sortie/mini.zip', 'Rien n\'est empaqueté.');
    }
}
