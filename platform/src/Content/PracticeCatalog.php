<?php

namespace App\Content;

use App\Entity\ExerciseProgress;
use App\Service\TrackProgress;
use Psr\Clock\ClockInterface;

/** Filtre, trie et groupe la Pratique visible pour sa page de liste. */
final readonly class PracticeCatalog
{
    /** Au-delà, les exercices passent dans le groupe « Avant ». */
    private const int JOURS_RECENTS = 7;

    public function __construct(
        private PracticeVisibility $practices,
        private ClockInterface $clock,
    ) {
    }

    /** @param array<string, ExerciseProgress> $progress la progression de Pratique de l'apprenant, par exercice */
    public function search(PracticeFilter $filter, array $progress): PracticeListing
    {
        $all = $this->practices->practices();

        // Les notions proposées et leur nombre se comptent sur le seul framework choisi : cocher une notion
        // ne doit pas faire fondre la liste des notions elle-même. Une notion que le framework choisi n'a pas
        // est oubliée, sinon changer de framework viderait la liste sans qu'on voie pourquoi.
        $ofFramework = array_filter($all, static fn (Practice $p) => null === $filter->framework || $p->framework === $filter->framework);
        $notionCounts = self::countConcepts($ofFramework);
        $filter = $filter->withNotions(array_values(array_intersect($filter->notions, array_keys($notionCounts))));

        $shown = array_filter(
            $ofFramework,
            static fn (Practice $p) => (!$filter->nouveautes || null !== $p->version)
                && ([] === $filter->notions || [] !== array_intersect($filter->notions, $p->exercise->concepts))
                && self::matches($p, $filter->recherche),
        );

        return new PracticeListing(
            filter: $filter,
            all: array_values($all),
            groups: $this->group(self::sort($shown, $filter->tri), $filter->tri, $progress),
            shown: \count($shown),
            notionCounts: $notionCounts,
            newCount: \count(array_filter($all, static fn (Practice $p) => null !== $p->version)),
            completedCount: \count(array_filter($progress, static fn (ExerciseProgress $p) => $p->isCompleted())),
        );
    }

    /** « Sécurité » se range entre « Serializer » et « Sessions », comme dans un index français. */
    private static function collator(): \Collator
    {
        return new \Collator('fr_FR');
    }

    /** Le titre, le résumé et les notions : ce que la liste montre déjà, donc ce qu'on cherche. */
    private static function matches(Practice $practice, string $words): bool
    {
        if ('' === $words) {
            return true;
        }

        $haystack = implode(' ', [$practice->exercise->title, $practice->summary, ...$practice->exercise->concepts]);

        return false !== mb_stripos($haystack, $words);
    }

    /**
     * @param array<string, Practice> $practices
     *
     * @return array<string, int> notion => nombre d'exercices, de la plus fournie à la moins fournie
     */
    private static function countConcepts(array $practices): array
    {
        $counts = [];
        foreach ($practices as $practice) {
            foreach ($practice->exercise->concepts as $concept) {
                $counts[$concept] = ($counts[$concept] ?? 0) + 1;
            }
        }
        $collator = self::collator();
        uksort($counts, static fn (string $a, string $b) => $counts[$b] <=> $counts[$a] ?: $collator->compare($a, $b));

        return $counts;
    }

    /**
     * @param array<string, Practice> $practices
     *
     * @return list<Practice>
     */
    private static function sort(array $practices, string $tri): array
    {
        $practices = array_values($practices);
        if (PracticeFilter::SORT_TITLE === $tri) {
            $collator = self::collator();
            usort($practices, static fn (Practice $a, Practice $b) => $collator->compare($a->exercise->title, $b->exercise->title));

            return $practices;
        }

        usort($practices, static fn (Practice $a, Practice $b) => $b->published <=> $a->published);

        return $practices;
    }

    /**
     * Des intertitres plutôt qu'une liste sans fin : par date, la semaine écoulée se détache du reste
     * (et, pour un administrateur, ce qui est encore à venir).
     *
     * @param list<Practice>                  $practices
     * @param array<string, ExerciseProgress> $progress
     *
     * @return list<array{label: string, items: list<array{practice: Practice, state: string}>}>
     */
    private function group(array $practices, string $tri, array $progress): array
    {
        $today = $this->clock->now()->setTime(0, 0);
        $recent = $today->modify(sprintf('-%d days', self::JOURS_RECENTS - 1));
        $groups = [];
        foreach ($practices as $practice) {
            $label = match (true) {
                PracticeFilter::SORT_TITLE === $tri => 'Par titre',
                $practice->isScheduled($today) => 'À venir',
                $practice->published >= $recent => 'Cette semaine',
                default => 'Avant',
            };
            $groups[$label][] = [
                'practice' => $practice,
                'state' => TrackProgress::stateFrom($progress[$practice->exercise->id] ?? null),
            ];
        }

        return array_map(static fn (string $label, array $items) => ['label' => $label, 'items' => $items], array_keys($groups), $groups);
    }
}
