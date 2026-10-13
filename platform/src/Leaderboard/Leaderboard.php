<?php

namespace App\Leaderboard;

use App\Content\TrackVisibility;
use App\Entity\User;
use App\Repository\ExerciseProgressRepository;
use App\Repository\TrackBonusRepository;
use Psr\Clock\ClockInterface;

/**
 * Le classement des apprenants à l'XP : ceux qui ont choisi d'y paraître (case de « Mon compte »), sous leur pseudo.
 * L'XP tient déjà compte des indices et de la solution consultée, plus le bonus des parcours terminés
 * (TrackBonuses) ; une réussite infirmée par le verificateur (voir App\Verification) n'y compte plus.
 *
 * Rien n'est stocké en plus des réussites : le classement d'un parcours filtre les réussites, et l'évolution du mois
 * compare avec le classement du mois précédent, recalculé.
 */
final readonly class Leaderboard
{
    /** Lignes affichées : au-delà, l'apprenant voit seulement son propre rang. */
    public const int TOP = 50;

    /** Le filtre « Pratique » : les réussites hors parcours. */
    public const string PRACTICE = 'pratique';

    public function __construct(
        private ExerciseProgressRepository $progress,
        private TrackBonusRepository $bonuses,
        private TrackVisibility $tracks,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Les filtres proposés : chaque parcours que l'apprenant peut voir, puis la Pratique.
     *
     * @return array<string, string> libellé par valeur du filtre
     */
    public function trackFilters(): array
    {
        $filters = [];
        foreach ($this->tracks->tracks() as $track) {
            $filters[$track->id] = $track->title;
        }

        return $filters + [self::PRACTICE => 'Pratique'];
    }

    /** @param string|null $track un parcours, self::PRACTICE, ou null pour toute l'XP */
    public function of(LeaderboardPeriod $period, ?User $viewer = null, ?string $track = null): LeaderboardView
    {
        $now = $this->clock->now();
        $since = $period->since($now);
        $listed = [];
        $own = null;
        foreach ($this->rows($since, null, $track, $viewer) as $row) {
            if (null !== $viewer && $row['userId'] === $viewer->getId() && !$viewer->isLeaderboardOptIn()) {
                $own = $row;
            } else {
                $listed[] = $row;
            }
        }

        // L'évolution du mois : le rang à la fin du mois précédent, sur le même filtre.
        $previousRanks = null;
        if (LeaderboardPeriod::Month === $period) {
            $previousRanks = [];
            foreach (self::ranked($this->rows($since->modify('-1 month'), $since, $track)) as [$rank, $row]) {
                $previousRanks[$row['userId']] = $rank;
            }
        }

        $ranked = self::ranked($listed);
        $shown = \array_slice($ranked, 0, self::TOP);
        $userIds = array_map(static fn (array $item) => $item[1]['userId'], $shown);
        if (null !== $viewer?->getId()) {
            $userIds[] = $viewer->getId();
        }
        $tracksByUser = $this->trackLabels($userIds);

        $entry = static fn (int $rank, array $row, bool $listed = true) => new LeaderboardEntry(
            $rank,
            $row['userId'],
            $row['displayName'],
            $row['xp'],
            $row['exercises'],
            $listed,
            $tracksByUser[$row['userId']] ?? [],
            null === $previousRanks || !isset($previousRanks[$row['userId']]) ? null : $previousRanks[$row['userId']] - $rank,
            null !== $previousRanks && !isset($previousRanks[$row['userId']]),
        );
        $entries = array_map(static fn (array $item) => $entry($item[0], $item[1]), $shown);

        $viewerEntry = null;
        $ahead = null;
        if (null !== $viewer) {
            $mine = null;
            if (null !== $own) {
                $better = array_filter($listed, static fn (array $row) => $row['xp'] > $own['xp']);
                $mine = [\count($better) + 1, $own, false];
            } else {
                foreach ($ranked as [$rank, $row]) {
                    if ($row['userId'] === $viewer->getId()) {
                        $mine = [$rank, $row, true];
                        break;
                    }
                }
            }
            if (null !== $mine) {
                $viewerEntry = $entry(...$mine);
                // Celui qu'il dépassera en premier : le dernier, dans l'ordre, à avoir plus d'XP que lui.
                foreach ($ranked as [$rank, $row]) {
                    if ($row['xp'] > $viewerEntry->xp && $row['userId'] !== $viewer->getId()) {
                        $ahead = $entry($rank, $row);
                    }
                }
            }
        }

        // Ce que l'apprenant a gagné ce mois-ci : de l'XP au classement de toujours, des places à celui du mois.
        $monthXp = null;
        if (null !== $viewerEntry && LeaderboardPeriod::AllTime === $period) {
            $monthXp = 0;
            foreach ($this->rows(LeaderboardPeriod::Month->since($now), null, $track, $viewer) as $row) {
                if ($row['userId'] === $viewer->getId()) {
                    $monthXp = $row['xp'];
                }
            }
        }

        return new LeaderboardView($period, $track, $since, $entries, \count($listed), $viewerEntry, $ahead, $monthXp);
    }

    /**
     * Les réussites et les bonus de parcours, additionnés par apprenant, du meilleur au moins bon.
     *
     * @return list<array{userId: int, displayName: string, xp: int, exercises: int}>
     */
    private function rows(?\DateTimeImmutable $since, ?\DateTimeImmutable $until, ?string $track, ?User $include = null): array
    {
        $rows = [];
        foreach ($this->progress->leaderboardRows($since, $include, $until, $track) as $row) {
            $rows[$row['userId']] = $row;
        }
        if (self::PRACTICE !== $track) {
            foreach ($this->bonuses->leaderboardRows($since, $include, $until, $track) as $row) {
                $rows[$row['userId']] ??= ['userId' => $row['userId'], 'displayName' => $row['displayName'], 'xp' => 0, 'exercises' => 0];
                $rows[$row['userId']]['xp'] += $row['xp'];
            }
        }
        $rows = array_values(array_filter($rows, static fn (array $row) => $row['xp'] > 0));
        usort($rows, static fn (array $a, array $b) => [$b['xp'], $b['exercises'], $a['displayName'], $a['userId']] <=> [$a['xp'], $a['exercises'], $b['displayName'], $b['userId']]);

        return $rows;
    }

    /**
     * Classement « sportif » : un ex aequo partage le rang, le suivant prend le rang de sa position.
     *
     * @param list<array{userId: int, displayName: string, xp: int, exercises: int}> $rows
     *
     * @return list<array{int, array{userId: int, displayName: string, xp: int, exercises: int}}>
     */
    private static function ranked(array $rows): array
    {
        $ranked = [];
        foreach ($rows as $index => $row) {
            $previous = $ranked[$index - 1] ?? null;
            $ranked[] = [null !== $previous && $previous[1]['xp'] === $row['xp'] ? $previous[0] : $index + 1, $row];
        }

        return $ranked;
    }

    /**
     * Les parcours suivis par chacun (ceux où il a réussi un exercice), dans l'ordre du site, la Pratique en dernier.
     * Un parcours que l'apprenant qui regarde ne voit pas n'est pas nommé.
     *
     * @param list<int> $userIds
     *
     * @return array<int, list<string>>
     */
    private function trackLabels(array $userIds): array
    {
        $filters = $this->trackFilters();
        $order = array_keys($filters);
        $labels = [];
        foreach ($this->progress->completedTrackIdsByUsers($userIds) as $userId => $trackIds) {
            $keys = array_map(static fn (?string $id) => $id ?? self::PRACTICE, $trackIds);
            $labels[$userId] = array_values(array_map(static fn (string $key) => $filters[$key], array_filter($order, static fn (string $key) => \in_array($key, $keys, true))));
        }

        return $labels;
    }
}
