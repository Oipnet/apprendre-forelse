<?php

namespace App\Tests\Service;

use App\Content\ContentRepository;
use App\Content\Exercise;
use App\Entity\ExerciseProgress;
use App\Entity\ProgressStatus;
use App\Entity\User;
use App\Service\ProgressService;
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
}
