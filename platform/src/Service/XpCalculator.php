<?php

namespace App\Service;

/**
 * XP d'un exercice réussi : −25 % par indice consulté, avec un plancher à 25 %.
 * Même règle que xpFor() dans playground/src/app/progress.ts (affichage côté navigateur).
 */
final class XpCalculator
{
    /** Ce que coûte chaque indice, en pourcentage de l'XP de l'exercice. */
    public const int HINT_PENALTY = 25;

    /** Ce qui reste au moins, en pourcentage, quel que soit le nombre d'indices. */
    public const int FLOOR = 25;

    public function xpFor(int $baseXp, int $hintsUsed): int
    {
        return (int) round($baseXp * max(self::FLOOR / 100, 1 - self::HINT_PENALTY / 100 * max(0, $hintsUsed)));
    }
}
