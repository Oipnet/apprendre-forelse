<?php

namespace App\Leaderboard;

use App\Content\ContentRepository;
use App\Content\TrackVisibility;
use App\Entity\User;
use App\Repository\ExerciseProgressRepository;
use App\Repository\TrackBonusRepository;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

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
    public const string PRACTICE = ContentRepository::PRACTICE;

    public function __construct(
        private ExerciseProgressRepository $progress,
        private TrackBonusRepository $bonuses,
        private TrackVisibility $tracks,
        private ClockInterface $clock,
        private TranslatorInterface $translator,
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

        return $filters + [self::PRACTICE => $this->translator->trans('commun.pratique', [], 'classement')];
    }

    /** @param string|null $track un parcours, self::PRACTICE, ou null pour toute l'XP */
    public function of(LeaderboardPeriod $period, ?User $viewer = null, ?string $track = null): LeaderboardView
    {
        $now = $this->clock->now();
        $since = $period->since($now);
        // L'évolution du mois : le rang à la fin du mois précédent, sur le même filtre.
        $ranking = new LeaderboardRanking(
            $this->rows($since, null, $track, $viewer),
            $viewer?->getId(),
            $viewer?->isLeaderboardOptIn() ?? true,
            LeaderboardPeriod::Month === $period ? $this->rows($since->modify('-1 month'), $since, $track) : null,
        );

        $shown = \array_slice($ranking->ranked, 0, self::TOP);
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
            $ranking->change($row['userId'], $rank),
            $ranking->isNew($row['userId']),
        );
        $entries = array_map(static fn (array $item) => $entry($item[0], $item[1]), $shown);
        $viewerEntry = null === $ranking->viewer ? null : $entry(...$ranking->viewer);
        $ahead = null === $ranking->ahead ? null : $entry(...$ranking->ahead);

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

        return new LeaderboardView($period, $track, $since, $entries, \count($ranking->ranked), $viewerEntry, $ahead, $monthXp, $this->periodNote($period, $since), $this->monthName(($since ?? $now)->modify('-1 month')));
    }

    /** Ce que couvre le classement, sous le tableau : « Classement d'octobre : il repart à zéro le 1er novembre… ». */
    private function periodNote(LeaderboardPeriod $period, ?\DateTimeImmutable $since): string
    {
        if (LeaderboardPeriod::AllTime === $period || null === $since) {
            return $this->translator->trans('index.note_toujours', [], 'classement');
        }

        return $this->translator->trans('index.note_mois', [
            'numero' => (int) $since->format('n'),
            'mois' => $this->monthName($since),
            'suivant' => $this->monthName($since->modify('+1 month')),
            'precedent' => $this->monthName($since->modify('-1 month')),
        ], 'classement');
    }

    /** Le mois en toutes lettres, dans la langue de la page : « septembre ». */
    private function monthName(\DateTimeImmutable $date): string
    {
        return (string) (new \IntlDateFormatter($this->translator->getLocale(), \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $date->getTimezone(), null, 'LLLL'))->format($date);
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
