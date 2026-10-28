<?php

namespace App\Tests\Leaderboard;

use App\Leaderboard\LeaderboardRanking;
use PHPUnit\Framework\TestCase;

/** L'algorithme du classement, sans base : chaque cas tient en quelques lignes. */
final class LeaderboardRankingTest extends TestCase
{
    /** @return array{userId: int, displayName: string, xp: int, exercises: int} */
    private static function row(int $userId, int $xp): array
    {
        return ['userId' => $userId, 'displayName' => 'U'.$userId, 'xp' => $xp, 'exercises' => 1];
    }

    public function testUnExAequoPartageLeRangEtLeSuivantPrendSaPosition(): void
    {
        $ranking = new LeaderboardRanking([self::row(1, 50), self::row(2, 40), self::row(3, 40), self::row(4, 10)]);

        $this->assertSame([1, 2, 2, 4], array_column($ranking->ranked, 0));
        $this->assertNull($ranking->viewer);
        $this->assertNull($ranking->previousRanks, 'Pas d\'évolution sans période précédente.');
    }

    public function testLApprenantVoitSaPlaceEtCeluiQuIlDepasseraEnPremier(): void
    {
        $ranking = new LeaderboardRanking([self::row(1, 50), self::row(2, 40), self::row(3, 30)], viewerId: 3);

        $this->assertSame([3, self::row(3, 30), true], $ranking->viewer);
        $this->assertSame([2, self::row(2, 40)], $ranking->ahead, 'Le plus proche devant lui, pas le premier.');
    }

    public function testUnApprenantQuiNeParaitPasVoitSaPlaceSansEnPrendreUne(): void
    {
        $ranking = new LeaderboardRanking([self::row(1, 50), self::row(2, 40), self::row(3, 30)], viewerId: 2, viewerListed: false);

        $this->assertSame([1, 2], array_column($ranking->ranked, 0), 'Il n\'est pas dans la liste : le suivant prend sa place.');
        $this->assertSame([2, self::row(2, 40), false], $ranking->viewer);
        $this->assertSame([1, self::row(1, 50)], $ranking->ahead);
    }

    public function testLEvolutionCompareAuRangDeLaPeriodePrecedente(): void
    {
        $ranking = new LeaderboardRanking(
            [self::row(1, 50), self::row(2, 40), self::row(3, 30)],
            previous: [self::row(2, 20), self::row(1, 10)],
        );

        $this->assertSame(1, $ranking->change(1, 1), 'Une place gagnée.');
        $this->assertSame(-1, $ranking->change(2, 2), 'Une place perdue.');
        $this->assertNull($ranking->change(3, 3));
        $this->assertTrue($ranking->isNew(3), 'Absent du mois précédent : nouveau venu.');
        $this->assertFalse($ranking->isNew(1));
    }
}
