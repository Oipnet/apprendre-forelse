<?php

namespace App\Tests\Leaderboard;

use App\Content\ContentRepository;
use App\Entity\ExerciseProgress;
use App\Entity\VerificationStatus;
use App\Leaderboard\TrackBonuses;
use App\Repository\TrackBonusRepository;
use App\Tests\DatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** Le bonus du parcours terminé, et son attribution aux parcours terminés avant lui. */
final class TrackBonusesTest extends KernelTestCase
{
    use DatabaseTrait;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = $this->resetDatabase();
    }

    private function complete(string $email, array $exerciseIds, string $completedAt = '2026-09-03 10:00:00', ?VerificationStatus $verification = null): \App\Entity\User
    {
        $user = $this->createUser($email);
        foreach ($exerciseIds as $exerciseId) {
            $progress = new ExerciseProgress($user, 'decouverte', $exerciseId);
            $progress->saveDraft(['src/A.php' => '<?php'], 0, new \DateTimeImmutable($completedAt));
            $progress->complete(0, 50, new \DateTimeImmutable($completedAt));
            if (null !== $verification) {
                $progress->recordVerification($verification, null, new \DateTimeImmutable($completedAt));
            }
            $this->entityManager->persist($progress);
        }
        $this->entityManager->flush();

        return $user;
    }

    public function testLeBonusVautDixPourCentDuParcours(): void
    {
        $track = static::getContainer()->get(ContentRepository::class)->findTrack('decouverte');

        $this->assertSame(10, static::getContainer()->get(TrackBonuses::class)->xpFor($track), '10 % des 100 XP du parcours.');
    }

    public function testLaCommandeAttribueLeBonusDesParcoursDejaTermines(): void
    {
        $done = $this->complete('ada@example.test', ['01-bonjour', '02-bonjour-prenom']);
        $this->complete('grace@example.test', ['01-bonjour']);
        $this->complete('alan@example.test', ['01-bonjour', '02-bonjour-prenom'], verification: VerificationStatus::Failed);

        $tester = new CommandTester((new Application(self::$kernel))->find('app:classement:bonus-parcours'));
        $this->assertSame(0, $tester->execute([]));
        $this->assertStringContainsString('1 bonus attribué(s).', $tester->getDisplay());

        $bonuses = static::getContainer()->get(TrackBonusRepository::class)->findAll();
        $this->assertCount(1, $bonuses, 'Ni le parcours inachevé, ni celui dont une réussite est infirmée.');
        $this->assertSame($done->getId(), $bonuses[0]->getUser()->getId());
        $this->assertSame('2026-09-03 10:00', $bonuses[0]->getAwardedAt()->format('Y-m-d H:i'), 'Daté de la dernière réussite.');
        $this->assertSame(10, $bonuses[0]->getXp());

        $tester->execute([]);
        $this->assertStringContainsString('Aucun bonus à attribuer.', $tester->getDisplay(), 'Relancée, elle ne double rien.');
    }
}
