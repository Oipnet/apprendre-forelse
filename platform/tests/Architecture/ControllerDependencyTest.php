<?php

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/** Le domaine ne dépend pas des contrôleurs : seul Controller/ peut nommer App\Controller. */
final class ControllerDependencyTest extends TestCase
{
    public function testAucuneClasseHorsDeControllerNImporteUnControleur(): void
    {
        $offenders = [];
        foreach ((new Finder())->files()->in(\dirname(__DIR__, 2).'/src')->exclude('Controller')->name('*.php') as $file) {
            if (str_contains($file->getContents(), 'App\\Controller\\')) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders);
    }
}
