<?php

namespace App\Tests\Content\Framework;

use App\Content\Framework\FrameworkProfile;
use App\Content\Framework\FrameworkRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Le lanceur de tests qu'un framework déclare : un spécificateur de paquet, résolu par Node.
 *
 * C'est ce qui permet à un runtime livré par un paquet d'apporter son propre lanceur sans que le moteur
 * sache où ce paquet est installé — ni qu'il existe.
 */
final class TestModuleTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../../..';

    /** Les frameworks qui ne lancent pas PHPUnit déclarent tous un module, sinon leurs tests n'ont pas de runner. */
    public function testUnFrameworkSansPhpunitDeclareSonLanceur(): void
    {
        $sansLanceur = [];
        foreach ((new FrameworkRegistry())->all() as $profile) {
            if (!$profile->runsPhpunit() && null === $profile->testModule) {
                $sansLanceur[] = $profile->id;
            }
        }

        $this->assertSame([], $sansLanceur, 'Ces frameworks ne lancent ni PHPUnit ni rien d\'autre.');
    }

    /** Et les frameworks PHP n'en déclarent pas : PHPUnit est le chemin intégré. */
    public function testUnFrameworkPhpunitNaPasDeLanceurExterne(): void
    {
        $profile = (new FrameworkRegistry())->get('symfony');

        $this->assertTrue($profile->runsPhpunit());
        $this->assertNull($profile->testModule);
    }

    /**
     * Le spécificateur déclaré se résout pour de vrai depuis le playground.
     *
     * Un chemin cassé ne se verrait qu'au lancement de `content:check` sur un exercice Nuxt — c'est-à-dire
     * tard, et seulement si Node est présent. Ici, il se voit tout de suite.
     */
    public function testLeModuleDeclareSeResoutDepuisLePlayground(): void
    {
        if (null === ($node = (new ExecutableFinder())->find('node'))) {
            $this->markTestSkipped('Node.js est introuvable.');
        }
        $module = (new FrameworkRegistry())->get('nuxt')->testModule;
        $this->assertNotNull($module);

        $process = new Process(
            [$node, '--input-type=module', '-e', sprintf('process.stdout.write(import.meta.resolve(%s))', json_encode($module, \JSON_THROW_ON_ERROR))],
            self::ROOT.'/playground',
            timeout: 30,
        );
        $process->run();

        $this->assertTrue($process->isSuccessful(), sprintf('« %s » ne se résout pas : %s', $module, $process->getErrorOutput()));
        $chemin = (string) parse_url(trim($process->getOutput()), \PHP_URL_PATH);
        $this->assertFileExists($chemin);
    }

    /** Le champ est facultatif : un profil écrit sans lui reste valide. */
    public function testLeChampEstFacultatif(): void
    {
        $profile = new FrameworkProfile(
            id: 'maison', label: 'Maison', order: 99, console: 'bin/console', consoleExample: 'liste',
            bootNote: '', unpackLabel: '', testRunner: FrameworkProfile::PHPUNIT, versionPackage: null,
            projectDirs: [], codeDirs: [], cacheDirs: [], testCaches: [], hidden: [],
            namespaceRoots: [], lessonLanguages: '', drafting: null,
        );

        $this->assertNull($profile->testModule);
        $this->assertArrayNotHasKey('testModule', $profile->forBrowser(), 'Le navigateur n\'a que faire du lanceur serveur.');
    }
}
