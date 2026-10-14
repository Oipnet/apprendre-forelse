<?php

namespace App\Tests\Content\Check;

use App\Content\Check\PhpunitRunner;
use App\Content\Check\ProcessEnvironment;
use App\Content\Check\RunReport;
use App\Content\Environment;
use App\Content\Framework\FrameworkProfile;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class RunReportTest extends TestCase
{
    public function testUnRapportDitCeQuiPasseEtCeQuiEchoue(): void
    {
        $report = RunReport::of([
            'testA' => ['status' => 'passed', 'file' => '/w/tests/ATest.php'],
            'testB' => ['status' => 'skipped', 'file' => '/w/tests/MesTest.php'],
        ]);

        $this->assertTrue($report->ran());
        $this->assertSame(['testA' => true, 'testB' => false], $report->passed(), 'Un test ignoré ne compte pas comme réussi.');
        $this->assertTrue($report->hasFailure());
        $this->assertSame(['testB'], array_keys($report->casesIn(['tests/MesTest.php'])));
    }

    public function testSansRapportLaRaisonEstGlobale(): void
    {
        $report = RunReport::none('PHP a planté (signal 11).');

        $this->assertFalse($report->ran());
        $this->assertSame([], $report->passed());
        $this->assertTrue($report->hasFailure(), 'Rien n\'a tourné : l\'application est cassée.');
        $this->assertSame('PHP a planté (signal 11).', $report->failures[RunReport::GLOBAL]);
    }

    public function testUnRapportJUnitVideDitPourquoi(): void
    {
        // PHPUnit ouvre son rapport puis meurt (ex. un test qui masque une méthode de KernelTestCase).
        $workdir = sys_get_temp_dir().'/phpunit-vide-'.bin2hex(random_bytes(4));
        $filesystem = new Filesystem();
        $filesystem->dumpFile($workdir.'/vendor/bin/phpunit', <<<'PHP'
            <?php
            $junit = $argv[array_search('--log-junit', $argv, true) + 1];
            @mkdir(dirname($junit), 0777, true);
            touch($junit);
            fwrite(STDERR, "PHP Fatal error:  Cannot make static method runCommand() non static\n");
            exit(255);
            PHP);
        $profile = new FrameworkProfile(
            id: 'maison', label: 'Maison', order: 99, console: '', consoleExample: '',
            bootNote: '', unpackLabel: '', testRunner: FrameworkProfile::PHPUNIT, versionPackage: null,
            projectDirs: [], codeDirs: [], cacheDirs: [], testCaches: [], hidden: [],
            namespaceRoots: [], lessonLanguages: '', drafting: null,
        );

        try {
            $report = (new PhpunitRunner(new ProcessEnvironment()))->run(new Environment('maison', 'Maison', '8.4', [$workdir], $profile, []), $workdir);
        } finally {
            $filesystem->remove($workdir);
        }

        $this->assertFalse($report->ran());
        $this->assertStringContainsString('Cannot make static method runCommand() non static', $report->failures[RunReport::GLOBAL]);
    }

    public function testLeRapportJUnitDePhpunitEstLu(): void
    {
        $report = PhpunitRunner::parse(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <testsuites>
              <testsuite name="t">
                <testcase name="testA" file="/w/tests/ATest.php"/>
                <testcase name="testB" file="/w/tests/ATest.php">
                  <failure type="Failure">App\Tests\ATest::testB
            Failed asserting that false is true.
            Le détail.
            /w/tests/ATest.php:12</failure>
                </testcase>
                <testcase name="testC" file="/w/tests/ATest.php"><skipped/></testcase>
              </testsuite>
            </testsuites>
            XML);

        $this->assertSame(['testA' => true, 'testB' => false, 'testC' => false], $report->passed());
        $this->assertSame(['testB' => 'Failed asserting that false is true. Le détail.'], $report->failures);
    }
}
