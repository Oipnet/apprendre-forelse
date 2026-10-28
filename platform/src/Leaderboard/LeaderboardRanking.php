<?php

namespace App\Leaderboard;

/**
 * L'algorithme du classement, sans base ni service : les rangs (ex aequo compris), la place de l'apprenant qui
 * regarde (même s'il n'a pas choisi d'y paraître), celui qu'il dépassera en premier, et les rangs du mois précédent.
 * Leaderboard récupère les lignes et habille le résultat.
 *
 * @phpstan-type Row array{userId: int, displayName: string, xp: int, exercises: int}
 */
final readonly class LeaderboardRanking
{
    /** @var list<array{int, Row}> les apprenants affichables, avec leur rang, du meilleur au moins bon */
    public array $ranked;
    /** @var array{int, Row, bool}|null le rang de l'apprenant qui regarde, sa ligne, et s'il paraît au classement */
    public ?array $viewer;
    /** @var array{int, Row}|null celui qu'il dépassera en premier : le dernier, dans l'ordre, à avoir plus d'XP que lui */
    public ?array $ahead;
    /** @var array<int, int>|null le rang de chacun sur la période précédente, par apprenant (null : pas d'évolution) */
    public ?array $previousRanks;

    /**
     * @param list<Row>      $rows         du meilleur au moins bon, l'apprenant qui regarde compris
     * @param int|null       $viewerId     l'apprenant qui regarde
     * @param bool           $viewerListed a-t-il choisi de paraître au classement ? Sinon, il voit sa place sans y être
     * @param list<Row>|null $previous     les lignes de la période précédente, pour l'évolution (null : sans objet)
     */
    public function __construct(array $rows, ?int $viewerId = null, bool $viewerListed = true, ?array $previous = null)
    {
        $listed = [];
        $own = null;
        foreach ($rows as $row) {
            if (null !== $viewerId && $row['userId'] === $viewerId && !$viewerListed) {
                $own = $row;
            } else {
                $listed[] = $row;
            }
        }
        $this->ranked = self::ranked($listed);

        if (null === $previous) {
            $this->previousRanks = null;
        } else {
            $previousRanks = [];
            foreach (self::ranked($previous) as [$rank, $row]) {
                $previousRanks[$row['userId']] = $rank;
            }
            $this->previousRanks = $previousRanks;
        }

        $mine = null;
        if (null !== $own) {
            $better = array_filter($listed, static fn (array $row) => $row['xp'] > $own['xp']);
            $mine = [\count($better) + 1, $own, false];
        } elseif (null !== $viewerId) {
            foreach ($this->ranked as [$rank, $row]) {
                if ($row['userId'] === $viewerId) {
                    $mine = [$rank, $row, true];
                    break;
                }
            }
        }
        $this->viewer = $mine;

        $ahead = null;
        if (null !== $mine) {
            foreach ($this->ranked as [$rank, $row]) {
                if ($row['xp'] > $mine[1]['xp'] && $row['userId'] !== $viewerId) {
                    $ahead = [$rank, $row];
                }
            }
        }
        $this->ahead = $ahead;
    }

    /** L'évolution d'un apprenant depuis la période précédente : des places gagnées (positif) ou perdues. */
    public function change(int $userId, int $rank): ?int
    {
        return null === $this->previousRanks || !isset($this->previousRanks[$userId]) ? null : $this->previousRanks[$userId] - $rank;
    }

    /** Absent de la période précédente : un nouveau venu (seulement quand il y a une évolution à montrer). */
    public function isNew(int $userId): bool
    {
        return null !== $this->previousRanks && !isset($this->previousRanks[$userId]);
    }

    /**
     * Classement « sportif » : un ex aequo partage le rang, le suivant prend le rang de sa position.
     *
     * @param list<Row> $rows
     *
     * @return list<array{int, Row}>
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
}
