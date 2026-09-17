<?php

namespace App\Admin;

final readonly class ChapterStats
{
    /** @param list<string> $exerciseIds */
    public function __construct(
        public string $title,
        public array $exerciseIds,
        public int $completed,
        /** Exercices réussis / (apprenants × exercices du chapitre). */
        public float $rate,
    ) {
    }
}
