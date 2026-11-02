<?php

namespace App\Tests\Content;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Le chargeur des paquets ajoutés au bac à sable (environments/<id>/.forelse/paquets.php) : il charge leurs classes, et
 * les déclare à Composer\InstalledVersions. Sans cela, Symfony croirait le paquet absent (ContainerBuilder::willBeAvailable)
 * et n'activerait ni la validation ni le sérialiseur. Lancé dans un autre processus : reload() changerait sinon les
 * paquets installés du moteur lui-même.
 */
final class PlaygroundPackagesLoaderTest extends TestCase
{
    private const ROOT = __DIR__.'/../../../environments';

    private string $projet;

    protected function setUp(): void
    {
        $this->projet = sys_get_temp_dir().'/paquets-playground-'.bin2hex(random_bytes(4));
        $fs = new Filesystem();
        $fs->copy(__DIR__.'/../../vendor/composer/ClassLoader.php', $this->projet.'/vendor/composer/ClassLoader.php');
        $fs->copy(__DIR__.'/../../vendor/composer/InstalledVersions.php', $this->projet.'/vendor/composer/InstalledVersions.php');
        $fs->dumpFile($this->projet.'/vendor/composer/installed.php', "<?php\nreturn ".var_export([
            'root' => ['name' => '__root__', 'pretty_version' => 'dev-main', 'version' => 'dev-main', 'reference' => null, 'type' => 'project', 'install_path' => $this->projet, 'aliases' => [], 'dev' => true],
            'versions' => ['symfony/framework-bundle' => ['pretty_version' => 'v8.1.0', 'version' => '8.1.0.0', 'reference' => null, 'type' => 'symfony-bundle', 'install_path' => $this->projet.'/vendor/symfony/framework-bundle', 'aliases' => [], 'dev_requirement' => false]],
        ], true).";\n");
        // Le vendor/autoload.php de l'archive, réduit à ce qui compte ici : un chargeur enregistré, puis les « files ».
        $fs->dumpFile($this->projet.'/vendor/autoload.php', "<?php\nrequire_once __DIR__.'/composer/ClassLoader.php';\nrequire_once __DIR__.'/composer/InstalledVersions.php';\n\$l = new Composer\\Autoload\\ClassLoader(__DIR__);\n\$l->register(true);\nrequire __DIR__.'/../.forelse/paquets.php';\nreturn \$l;\n");
        $fs->dumpFile($this->projet.'/vendor/symfony/validator/Validation.php', "<?php\nnamespace Symfony\\Component\\Validator;\nfinal class Validation {}\n");
        $fs->dumpFile($this->projet.'/vendor/forelse-paquets/symfony--validator.php', "<?php\nreturn ".var_export([
            'nom' => 'symfony/validator',
            'version' => 'v8.1.10',
            'paquets' => ['symfony/validator' => 'v8.1.10'],
            'classes' => ['Symfony\\Component\\Validator\\Validation' => 'symfony/validator/Validation.php'],
            'fichiers' => [],
        ], true).";\n");
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projet);
    }

    public function testLesPaquetsAjoutesSontChargesEtConnusDeComposer(): void
    {
        foreach (['php-8', 'symfony-8-doctrine'] as $environnement) {
            (new Filesystem())->copy(self::ROOT.'/'.$environnement.'/.forelse/paquets.php', $this->projet.'/.forelse/paquets.php', true);
            $process = new Process([\PHP_BINARY, '-r', <<<'PHP'
                require 'vendor/autoload.php';
                echo json_encode([
                    'classe' => class_exists(Symfony\Component\Validator\Validation::class),
                    'installe' => Composer\InstalledVersions::isInstalled('symfony/validator'),
                    'version' => Composer\InstalledVersions::getVersion('symfony/validator'),
                    'base' => Composer\InstalledVersions::isInstalled('symfony/framework-bundle'),
                    'absent' => Composer\InstalledVersions::isInstalled('symfony/form'),
                ]);
                PHP], $this->projet);
            $process->mustRun();

            self::assertSame(['classe' => true, 'installe' => true, 'version' => '8.1.10.0', 'base' => true, 'absent' => false], json_decode($process->getOutput(), true), $environnement);
        }
    }

    public function testLesDeuxEnvironnementsPartagentLeMemeChargeur(): void
    {
        self::assertFileEquals(self::ROOT.'/php-8/.forelse/paquets.php', self::ROOT.'/symfony-8-doctrine/.forelse/paquets.php');
    }
}
