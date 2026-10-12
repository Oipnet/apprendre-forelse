<?php

namespace App\Tests\Leaderboard;

use App\Entity\ExerciseProgress;
use App\Entity\User;
use App\Entity\VerificationStatus;
use App\Leaderboard\Leaderboard;
use App\Leaderboard\LeaderboardPeriod;
use App\Repository\ExerciseProgressRepository;
use App\Tests\DatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

final class LeaderboardTest extends KernelTestCase
{
    use DatabaseTrait;

    private EntityManagerInterface $entityManager;
    private Leaderboard $leaderboard;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = $this->resetDatabase();
        $this->leaderboard = new Leaderboard(static::getContainer()->get(ExerciseProgressRepository::class), new MockClock('2026-10-12 20:00:00'));
    }

    private function learner(string $name, bool $optIn = true): User
    {
        $user = $this->createUser(strtolower($name).'@example.test', $name)->setLeaderboardOptIn($optIn);
        $this->entityManager->flush();

        return $user;
    }

    private function success(User $user, string $exerciseId, int $xp, string $completedAt = '2026-10-05 10:00:00', ?VerificationStatus $verification = null): void
    {
        $progress = new ExerciseProgress($user, null, $exerciseId);
        $progress->saveDraft(['src/A.php' => '<?php'], 0, new \DateTimeImmutable($completedAt));
        $progress->complete(0, $xp, new \DateTimeImmutable($completedAt));
        if (null !== $verification) {
            $progress->recordVerification($verification, null, new \DateTimeImmutable($completedAt));
        }
        $this->entityManager->persist($progress);
        $this->entityManager->flush();
    }

    /** @return list<array{int, string, int, int}> rang, pseudo, XP, exercices */
    private function rows(LeaderboardPeriod $period, ?User $viewer = null): array
    {
        return array_map(static fn ($entry) => [$entry->rank, $entry->displayName, $entry->xp, $entry->exercises], $this->leaderboard->of($period, $viewer)->entries);
    }

    public function testSeulsLesApprenantsQuiLOntChoisiSontClassesALXp(): void
    {
        $ada = $this->learner('Ada');
        $grace = $this->learner('Grace');
        $alan = $this->learner('Alan', optIn: false);
        $this->success($ada, 'a', 50);
        $this->success($grace, 'a', 50);
        $this->success($grace, 'b', 25);
        $this->success($alan, 'a', 500);

        $this->assertSame([[1, 'Grace', 75, 2], [2, 'Ada', 50, 1]], $this->rows(LeaderboardPeriod::AllTime));
    }

    public function testLesExAequoPartagentLeurRang(): void
    {
        foreach (['Ada' => 50, 'Grace' => 50, 'Alan' => 20] as $name => $xp) {
            $this->success($this->learner($name), 'a', $xp);
        }

        $this->assertSame([[1, 'Ada', 50, 1], [1, 'Grace', 50, 1], [3, 'Alan', 20, 1]], $this->rows(LeaderboardPeriod::AllTime));
    }

    public function testUneReussiteInfirmeeNeComptePlusUneReussiteEnAttenteCompteDeja(): void
    {
        $ada = $this->learner('Ada');
        $this->success($ada, 'a', 50, verification: VerificationStatus::Passed);
        $this->success($ada, 'b', 40, verification: VerificationStatus::Failed);
        $this->success($ada, 'c', 30);
        $this->success($ada, 'd', 20, verification: VerificationStatus::Unverifiable);

        $this->assertSame([[1, 'Ada', 100, 3]], $this->rows(LeaderboardPeriod::AllTime));
    }

    public function testUnCompteRetireParLAdminDisparait(): void
    {
        $ada = $this->learner('Ada');
        $this->success($ada, 'a', 50);
        $ada->setLeaderboardExcluded(true);
        $this->entityManager->flush();

        $this->assertSame([], $this->rows(LeaderboardPeriod::AllTime));
    }

    public function testLeClassementDuMoisNeCompteQueLesReussitesDuMois(): void
    {
        $ada = $this->learner('Ada');
        $grace = $this->learner('Grace');
        $this->success($ada, 'a', 500, '2026-09-30 23:00:00');
        $this->success($ada, 'b', 10, '2026-10-01 00:00:00');
        $this->success($grace, 'a', 20, '2026-10-11 09:00:00');

        $this->assertSame([[1, 'Grace', 20, 1], [2, 'Ada', 10, 1]], $this->rows(LeaderboardPeriod::Month));
        $this->assertSame([[1, 'Ada', 510, 2], [2, 'Grace', 20, 1]], $this->rows(LeaderboardPeriod::AllTime));
    }

    public function testLApprenantQuiNyParaitPasVoitOuIlSePlacerait(): void
    {
        $ada = $this->learner('Ada');
        $grace = $this->learner('Grace');
        $alan = $this->learner('Alan', optIn: false);
        $this->success($ada, 'a', 50);
        $this->success($grace, 'a', 10);
        $this->success($alan, 'a', 30);

        $board = $this->leaderboard->of(LeaderboardPeriod::AllTime, $alan);

        $this->assertSame([[1, 'Ada', 50, 1], [2, 'Grace', 10, 1]], $this->rows(LeaderboardPeriod::AllTime, $alan), 'Il ne paraît pas dans la liste.');
        $this->assertSame(2, $board->viewer?->rank);
        $this->assertFalse($board->viewer->listed);
        $this->assertSame(2, $board->total);
    }

    public function testLApprenantClasseRetrouveSaLigne(): void
    {
        $ada = $this->learner('Ada');
        $this->success($ada, 'a', 50);

        $board = $this->leaderboard->of(LeaderboardPeriod::AllTime, $ada);

        $this->assertSame(1, $board->viewer?->rank);
        $this->assertTrue($board->viewer->listed);
    }
}
