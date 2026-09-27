<?php

namespace App\Tests\Content\Check;

use App\Content\Check\PhpunitRunner;
use App\Content\Check\RunReport;
use PHPUnit\Framework\TestCase;

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
