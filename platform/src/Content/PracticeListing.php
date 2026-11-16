<?php

namespace App\Content;

/** La liste de Pratique filtrée, groupée, découpée en pages, et les chiffres qui l'entourent. */
final readonly class PracticeListing
{
    /**
     * @param list<Practice>                                                                  $all      toute la Pratique visible
     * @param list<array{id: string, label: string, items: list<array{practice: Practice, state: string}>}> $groups   la page demandée seulement
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
        public int $page = 1,
        public int $pageCount = 1,
        public int $perPage = PHP_INT_MAX,
    ) {
    }

    /** La page demandée existe-t-elle ? Une liste vide a quand même sa première page, qui le dit. */
    public function hasPage(): bool
    {
        return $this->page >= 1 && $this->page <= $this->pageCount;
    }

    /** Le rang du premier et du dernier exercice de la page, comptés sur toute la liste filtrée (21 et 40). */
    public function firstShown(): int
    {
        return min($this->shown, ($this->page - 1) * $this->perPage + 1);
    }

    public function lastShown(): int
    {
        return min($this->shown, $this->page * $this->perPage);
    }

    /**
     * Les numéros de page à proposer : la première, la dernière, et deux voisines de chaque côté de la page
     * courante. null marque un saut (« … »). Une seule page : rien à proposer.
     *
     * @return list<int|null>
     */
    public function pageLinks(): array
    {
        if ($this->pageCount <= 1) {
            return [];
        }
        $links = [];
        $previous = 0;
        foreach (range(1, $this->pageCount) as $n) {
            if (1 !== $n && $this->pageCount !== $n && abs($n - $this->page) > 2) {
                continue;
            }
            if ($n - $previous > 1) {
                $links[] = null;
            }
            $links[] = $previous = $n;
        }

        return $links;
    }
}
