<?php

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * La ligne rouge de l'hexagonal : le domaine du contexte Accès ne dépend que de lui-même et de PHP.
 * Ni Symfony, ni Doctrine, ni Stripe, ni le reste de l'application (App\Entity, App\Repository…).
 */
final class AccessDomainDependencyTest extends TestCase
{
    public function testLeDomaineAccesNImporteQueLuiMeme(): void
    {
        $dir = \dirname(__DIR__, 2).'/src/Domain/Access';
        $this->assertDirectoryExists($dir, 'Le domaine Accès n\'existe pas encore : c\'est le pas 1.');

        $offenders = [];
        foreach ((new Finder())->files()->in($dir)->name('*.php') as $file) {
            preg_match_all('/^use\s+(?:function\s+|const\s+)?([^;\s]+)/m', $file->getContents(), $matches);
            foreach ($matches[1] as $import) {
                if (str_contains($import, '\\') && !str_starts_with($import, 'App\\Domain\\Access\\')) {
                    $offenders[] = $file->getRelativePathname().' : '.$import;
                }
            }
        }

        $this->assertSame([], $offenders);
    }
}
