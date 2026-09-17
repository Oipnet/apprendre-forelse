<?php

namespace App\Service;

/**
 * XP d'un exercice réussi : −25 % par indice consulté, avec un plancher à 25 %.
 * Même règle que xpFor() dans playground/src/app/progress.ts (affichage côté navigateur).
 */
final class XpCalculator
{
    public function xpFor(int $baseXp, int $hintsUsed): int
    {
        return (int) round($baseXp * max(0.25, 1 - 0.25 * max(0, $hintsUsed)));
    }
}
