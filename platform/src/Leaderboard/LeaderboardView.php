<?php

namespace App\Leaderboard;

/** Le classement d'une période, tel que la page l'affiche. */
final readonly class LeaderboardView
{
    /** Le podium s'affiche à partir de ce nombre de classés. */
    public const int PODIUM = 3;

    /**
     * @param list<LeaderboardEntry> $entries les premiers, dans l'ordre
     */
    public function __construct(
        public LeaderboardPeriod $period,
        /** Un parcours, Leaderboard::PRACTICE, ou null pour toute l'XP. */
        public ?string $track,
        public ?\DateTimeImmutable $since,
        public array $entries,
        /** Les apprenants classés sur la période, au-delà des premiers affichés. */
        public int $total,
        /** La ligne de l'apprenant qui regarde, même hors des premiers, ou s'il n'a pas choisi d'y paraître. */
        public ?LeaderboardEntry $viewer,
        /** Le classé juste devant lui, à dépasser. */
        public ?LeaderboardEntry $ahead = null,
        /** Son XP gagnée ce mois-ci, au classement de toujours. */
        public ?int $monthXp = null,
        /** Ce que couvre le classement, déjà traduit (voir Leaderboard::periodNote()). */
        private string $periodNote = '',
        /** Le mois précédent, en toutes lettres et dans la langue de la page. */
        private string $previousMonth = '',
    ) {
    }

    public function hasPodium(): bool
    {
        return $this->total >= self::PODIUM;
    }

    /** @return list<LeaderboardEntry> les trois premiers dans l'ordre du podium : deuxième, premier, troisième */
    public function podium(): array
    {
        if (!$this->hasPodium()) {
            return [];
        }

        return [$this->entries[1], $this->entries[0], $this->entries[2]];
    }

    /** @return list<LeaderboardEntry> les lignes du tableau : après le podium, ou toutes sans lui */
    public function rows(): array
    {
        return $this->hasPodium() ? \array_slice($this->entries, self::PODIUM) : $this->entries;
    }

    /** L'XP du premier : la référence des barres. */
    public function maxXp(): int
    {
        return $this->entries[0]->xp ?? 0;
    }

    /** Le classement du mois montre l'évolution depuis le mois précédent. */
    public function showsMoves(): bool
    {
        return LeaderboardPeriod::Month === $this->period;
    }

    /** Les places libres à montrer tant que le podium n'est pas formé. */
    public function freeSpots(): int
    {
        return max(0, self::PODIUM - \count($this->entries));
    }

    /** Le mois précédent, en toutes lettres : « septembre ». */
    public function previousMonth(): string
    {
        return $this->previousMonth;
    }

    /** Ce que couvre le classement, sous le tableau. */
    public function periodNote(): string
    {
        return $this->periodNote;
    }
}
