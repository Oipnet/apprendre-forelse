<?php

namespace App\Tests\Service;

use App\Service\XpCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * XP d'un exercice réussi : −25 % par indice, plancher à 25 % (4 indices ou plus). Les cas viennent de
 * tests/Fixtures/xp-cases.json, que playground/tests/xp.test.ts vérifie aussi contre xpFor() : l'XP affichée dans le
 * navigateur est celle que le serveur enregistre.
 */
final class XpCalculatorTest extends TestCase
{
    /** @return iterable<string, array{int, int, int}> */
    public static function cases(): iterable
    {
        $document = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/xp-cases.json'), true, flags: \JSON_THROW_ON_ERROR);
        foreach ($document['cases'] as $case) {
            yield sprintf('%d XP, %d indice(s) : %s', $case['baseXp'], $case['hintsUsed'], $case['cas']) => [$case['baseXp'], $case['hintsUsed'], $case['xp']];
        }
    }

    #[DataProvider('cases')]
    public function testXpSelonLesIndicesConsultes(int $baseXp, int $hintsUsed, int $expected): void
    {
        $this->assertSame($expected, (new XpCalculator())->xpFor($baseXp, $hintsUsed));
    }
}
