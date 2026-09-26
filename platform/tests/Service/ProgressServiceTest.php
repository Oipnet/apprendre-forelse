<?php

namespace App\Tests\Service;

use App\Api\ExerciseAccessGuard;
use App\Content\ContentRepository;
use App\Content\Exercise;
use App\Entity\ExerciseProgress;
use App\Entity\ProgressStatus;
use App\Entity\User;
use App\Repository\ExerciseProgressRepository;
use App\Service\ProgressService;
use App\Service\XpCalculator;
use App\Tests\DatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Réussites concurrentes. Une autre requête est simulée par une mise à jour SQL faite derrière le dos de l'EntityManager :
 * les objets déjà chargés gardent l'état d'avant, comme dans une requête qui a lu avant que l'autre n'écrive.
 */
final class ProgressServiceTest extends KernelTestCase
{
    use DatabaseTrait;

    private EntityManagerInterface $entityManager;
    private ProgressService $progress;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = $this->resetDatabase();
        $this->progress = static::getContainer()->get(ProgressService::class);
    }

    private function exercise(string $id): Exercise
    {
        $exercise = static::getContainer()->get(ContentRepository::class)->findExercise('decouverte', $id);
        $this->assertNotNull($exercise);

        return $exercise;
    }

    /** Ce qu'aurait écrit une autre requête : $xp de plus sur le compte, et l'exercice réussi s'il est donné. */
    private function concurrentCompletion(User $user, int $xp, ?ExerciseProgress $progress = null): void
    {
        $this->entityManager->createQuery('UPDATE '.User::class.' u SET u.xp = u.xp + :xp WHERE u.id = :id')
            ->execute(['xp' => $xp, 'id' => $user->getId()]);
        if (null !== $progress) {
            $this->entityManager->createQuery('UPDATE '.ExerciseProgress::class.' p SET p.status = :done, p.xpEarned = :xp WHERE p.id = :id')
                ->execute(['done' => ProgressStatus::Completed, 'xp' => $xp, 'id' => $progress->getId()]);
        }
    }

    private function xpInDatabase(User $user): int
    {
        return (int) $this->entityManager->createQuery('SELECT u.xp FROM '.User::class.' u WHERE u.id = :id')
            ->setParameter('id', $user->getId())
            ->getSingleScalarResult();
    }

    public function testDeuxReussitesDuMemeExerciceNeComptentLXpQuUneFois(): void
    {
        $ada = $this->createUser();
        $exercise = $this->exercise('01-bonjour');
        // Brouillon enregistré : la progression est chargée, pas encore réussie.
        $draft = $this->progress->saveDraft($ada, $exercise, [], 0);

        // L'autre requête (double clic) réussit l'exercice pendant que celle-ci attend.
        $this->concurrentCompletion($ada, $exercise->xp, $draft);
        $result = $this->progress->complete($ada, $exercise, 0);

        $this->assertTrue($result['alreadyCompleted']);
        $this->assertSame(0, $result['xpEarned']);
        $this->assertSame($exercise->xp, $result['totalXp']);
        $this->assertSame($exercise->xp, $this->xpInDatabase($ada), 'L\'XP n\'est comptée qu\'une fois.');
    }

    public function testDeuxExercicesReussisEnMemeTempsGardentToutesLeursXp(): void
    {
        $ada = $this->createUser();
        $first = $this->exercise('01-bonjour');
        $second = $this->exercise('02-bonjour-prenom');

        // Le premier exercice est réussi par une autre requête, alors que celle-ci a déjà lu le compte à 0 XP.
        $this->concurrentCompletion($ada, $first->xp);
        $result = $this->progress->complete($ada, $second, 0);

        $this->assertSame($second->xp, $result['xpEarned']);
        $this->assertSame($first->xp + $second->xp, $result['totalXp']);
        $this->assertSame($first->xp + $second->xp, $this->xpInDatabase($ada), 'Aucune XP n\'est perdue.');
    }

    public function testUneReussiteSansBrouillonCreeLaProgression(): void
    {
        $ada = $this->createUser();
        $exercise = $this->exercise('01-bonjour');

        $result = $this->progress->complete($ada, $exercise, 0);

        $this->assertFalse($result['alreadyCompleted']);
        $this->assertSame($exercise->xp, $this->xpInDatabase($ada));
        $this->entityManager->clear();
        $this->assertTrue($this->entityManager->getRepository(ExerciseProgress::class)->findOneBy(['exerciseId' => '01-bonjour'])?->isCompleted());
    }

    public function testUnPremierBrouillonCreeEnMemeTempsParUneAutreRequeteNEchouePas(): void
    {
        $ada = $this->createUser();
        $exercise = $this->exercise('01-bonjour');

        // Premier PUT de brouillon pendant que la première réussite crée la même ligne.
        $draft = $this->withConcurrentCreation($ada)->saveDraft($ada, $exercise, [], 1);

        $this->assertSame(1, $draft->getHintsUsed());
        $this->assertSame(1, $this->progressRows($ada), 'Une seule progression pour l\'exercice.');
    }

    public function testUnePremiereReussiteCreeeEnMemeTempsParUneAutreRequeteNEchouePas(): void
    {
        $ada = $this->createUser();
        $exercise = $this->exercise('01-bonjour');

        // Première réussite pendant que le premier brouillon crée la même ligne.
        $result = $this->withConcurrentCreation($ada)->complete($ada, $exercise, 0);

        $this->assertSame($exercise->xp, $result['xpEarned']);
        $this->assertSame($exercise->xp, $this->xpInDatabase($ada));
        $this->assertSame(1, $this->progressRows($ada), 'Une seule progression pour l\'exercice.');
        $this->entityManager->clear();
        $this->assertTrue($this->entityManager->getRepository(ExerciseProgress::class)->findOneBy(['exerciseId' => '01-bonjour'])?->isCompleted());
    }

    /**
     * Le service, avec un dépôt qui laisse une autre requête créer la progression juste après avoir constaté qu'elle
     * n'existait pas : l'intervalle où deux premières écritures simultanées se croisent.
     */
    private function withConcurrentCreation(User $user): ProgressService
    {
        $repository = new class(static::getContainer()->get('doctrine')) extends ExerciseProgressRepository {
            private bool $armed = true;

            public function findOne(User $user, ?string $trackId, string $exerciseId): ?ExerciseProgress
            {
                $found = parent::findOne($user, $trackId, $exerciseId);
                if (null === $found && $this->armed) {
                    $this->armed = false;
                    $this->getEntityManager()->getConnection()->executeStatement(
                        "INSERT INTO exercise_progress (user_id, track_id, exercise_id, status, files, hints_used, xp_earned, solution_revealed, started_at, updated_at)
                         VALUES (?, ?, ?, 'in_progress', '[]', 0, 0, false, NOW(), NOW())",
                        [$user->getId(), $trackId, $exerciseId],
                    );
                }

                return $found;
            }
        };
        $container = static::getContainer();

        return new ProgressService($repository, $this->entityManager, $container->get(XpCalculator::class), $container->get(ContentRepository::class), $container->get(ExerciseAccessGuard::class));
    }

    private function progressRows(User $user): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM exercise_progress WHERE user_id = ?', [$user->getId()]);
    }
}
