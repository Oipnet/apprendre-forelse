<?php

namespace App\Tests\Content\Check;

use App\Content\Check\PracticeVersionCheck;
use Composer\Semver\VersionParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Une nouveauté annoncée pour 8.2 tourne sur 8.2, sur sa branche de développement et sur ses préversions. */
final class PracticeVersionCheckTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function versions(): iterable
    {
        yield 'version finale' => ['v8.2.3', '8.2', true];
        yield 'branche de développement' => ['8.2.x-dev', '8.2', true];
        yield 'bêta' => ['v8.2.0-BETA1', '8.2', true];
        yield 'release candidate' => ['v8.2.0-RC2', '8.2', true];
        yield 'version précédente' => ['v8.1.11', '8.2', false];
        yield 'bêta d\'une version précédente' => ['v8.2.0-BETA1', '8.3', false];
        yield 'PHP' => ['8.5.12', '8.6', false];
    }

    #[DataProvider('versions')]
    public function testUneVersionApporteLesNouveautesAnnoncees(string $installed, string $announced, bool $reaches): void
    {
        $this->assertSame($reaches, PracticeVersionCheck::reaches(new VersionParser(), $installed, $announced));
    }
}
