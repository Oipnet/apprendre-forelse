<?php

namespace App\Content;

/** La liste de Pratique filtrée, groupée, et les chiffres qui l'entourent. */
final readonly class PracticeListing
{
    /**
     * @param list<Practice>                                                                  $all      toute la Pratique visible
     * @param list<array{label: string, items: list<array{practice: Practice, state: string}>}> $groups
     * @param array<string, int>                                                              $notionCounts notion => nombre d'exercices du framework choisi
     */
    public function __construct(
        public PracticeFilter $filter,
        public array $all,
        public array $groups,
        public int $shown,
        public array $notionCounts,
        public int $newCount,
        public int $completedCount,
    ) {
    }
}
