<?php

namespace App\Tests\Verification;

use App\Certificate\CertificateRevocation;
use App\Content\ContentRepository;
use App\Entity\ExerciseProgress;
use App\Entity\VerificationStatus;
use App\Leaderboard\TrackBonuses;
use App\Repository\ExerciseProgressRepository;
use App\Tests\DatabaseTrait;
use App\Verification\CollectVerifications;
use App\Verification\RequestVerifications;
use App\Verification\VerificationDispatcher;
use App\Verification\VerificationQueue;
use App\Verification\VerificationRequest;
use App\Verification\VerificationResult;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

/** Le côté worker : les réussites partent au verificateur, ses verdicts reviennent en base. */
final class VerificationDispatcherTest extends KernelTestCase
{
    use DatabaseTrait;

    private EntityManagerInterface $entityManager;
    private string $directory;
    private VerificationQueue $queue;
    private MockClock $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = $this->resetDatabase();
        $this->directory = sys_get_temp_dir().'/verifications-'.bin2hex(random_bytes(4));
        $this->queue = new VerificationQueue($this->directory);
        $this->clock = new MockClock('2026-10-12 20:00:00');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
        parent::tearDown();
    }

    private function dispatcher(?VerificationQueue $queue = null): VerificationDispatcher
    {
        $container = static::getContainer();

        return new VerificationDispatcher($queue ?? $this->queue, $container->get(ExerciseProgressRepository::class), $this->entityManager, $this->clock, $container->get(CertificateRevocation::class), $container->get(ContentRepository::class), $container->get(TrackBonuses::class));
    }

    /** @param array<string, string> $files */
    private function progress(string $exerciseId, array $files, bool $completed = true): ExerciseProgress
    {
        $user = $this->createUser($exerciseId.'@example.test', $exerciseId);
        $progress = new ExerciseProgress($user, null, $exerciseId);
        $progress->saveDraft($files, 0, $this->clock->now());
        if ($completed) {
            $progress->complete(0, 50, $this->clock->now());
        }
        $this->entityManager->persist($progress);
        $this->entityManager->flush();

        return $progress;
    }

    public function testLesReussitesEnAttentePartentUneFois(): void
    {
        $pending = $this->progress('a', ['src/A.php' => '<?php // réussi']);
        $this->progress('b', ['src/A.php' => '<?php // en cours'], completed: false);
        // Sans code, la réussite part quand même : le verificateur rejoue le code de départ et l'infirme.
        $empty = $this->progress('c', []);

        $this->assertSame(2, $this->dispatcher()->request(new RequestVerifications()));
        $requests = [$this->queue->pop(), $this->queue->pop()];
        usort($requests, static fn (?VerificationRequest $x, ?VerificationRequest $y) => $x?->exerciseId <=> $y?->exerciseId);
        $this->assertEquals([
            new VerificationRequest((int) $pending->getId(), null, 'a', ['src/A.php' => '<?php // réussi']),
            new VerificationRequest((int) $empty->getId(), null, 'c', []),
        ], $requests);
        $this->assertNull($this->queue->pop(), 'Pas la progression en cours.');

        $this->assertSame(0, $this->dispatcher()->request(new RequestVerifications()), 'Déjà envoyées.');
        $this->clock->modify('+25 hours');
        $this->assertSame(2, $this->dispatcher()->request(new RequestVerifications()), 'Sans verdict au bout d\'un jour, elles repartent.');
    }

    public function testLesVerdictsSontEnregistres(): void
    {
        $passed = $this->progress('a', ['src/A.php' => '<?php']);
        $failed = $this->progress('b', ['src/A.php' => '<?php']);
        $this->queue->pushResult(new VerificationResult((int) $passed->getId(), VerificationStatus::Passed));
        $this->queue->pushResult(new VerificationResult((int) $failed->getId(), VerificationStatus::Failed, 'objectif manqué'));
        $this->queue->pushResult(new VerificationResult(999, VerificationStatus::Passed));

        $this->assertSame(2, $this->dispatcher()->collect(new CollectVerifications()));

        $this->entityManager->clear();
        $repository = static::getContainer()->get(ExerciseProgressRepository::class);
        $this->assertSame(VerificationStatus::Passed, $repository->find($passed->getId())->getVerification());
        $failed = $repository->find($failed->getId());
        $this->assertSame(VerificationStatus::Failed, $failed->getVerification());
        $this->assertSame('objectif manqué', $failed->getVerificationDetail());
        $this->assertEquals(new \DateTimeImmutable('2026-10-12 20:00:00'), $failed->getVerifiedAt());
        $this->assertSame(0, $this->dispatcher()->request(new RequestVerifications()), 'Jugées : plus rien à envoyer.');
    }

    public function testSansDossierRienNePart(): void
    {
        $this->progress('a', ['src/A.php' => '<?php']);

        $this->assertSame(0, $this->dispatcher(new VerificationQueue(''))->request(new RequestVerifications()));
        $this->assertSame(0, $this->dispatcher(new VerificationQueue(''))->collect(new CollectVerifications()));
    }
}
