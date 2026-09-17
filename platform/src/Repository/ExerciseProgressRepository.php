<?php

namespace App\Repository;

use App\Entity\ExerciseProgress;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExerciseProgress>
 */
class ExerciseProgressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExerciseProgress::class);
    }

    /** @param string|null $trackId null pour un exercice de Pratique */
    public function findOne(User $user, ?string $trackId, string $exerciseId): ?ExerciseProgress
    {
        // findOneBy traduit null en « IS NULL ».
        return $this->findOneBy(['user' => $user, 'trackId' => $trackId, 'exerciseId' => $exerciseId]);
    }

    /** @return array<string, ExerciseProgress> progression en Pratique, par identifiant d'exercice */
    public function findPractice(User $user): array
    {
        return $this->findByTrack($user, null);
    }

    /**
     * Toute la progression d'un groupe d'apprenants dans les parcours (tableau de bord), sans la Pratique.
     *
     * @param list<User> $users
     *
     * @return list<ExerciseProgress>
     */
    public function findByUsers(array $users): array
    {
        return $users ? $this->createQueryBuilder('p')
            ->andWhere('p.user IN (:users)')
            ->andWhere('p.trackId IS NOT NULL')
            ->setParameter('users', $users)
            ->orderBy('p.id')
            ->getQuery()
            ->getResult() : [];
    }

    /** @return array<string, ExerciseProgress> progression par identifiant d'exercice (null : la Pratique) */
    public function findByTrack(User $user, ?string $trackId): array
    {
        $byExercise = [];
        foreach ($this->findBy(['user' => $user, 'trackId' => $trackId]) as $progress) {
            $byExercise[$progress->getExerciseId()] = $progress;
        }

        return $byExercise;
    }

    /** @return list<string> les parcours où l'apprenant a au moins un exercice ouvert */
    public function findStartedTrackIds(User $user): array
    {
        return array_column($this->createQueryBuilder('p')
            ->select('DISTINCT p.trackId')
            ->andWhere('p.user = :user')
            ->andWhere('p.trackId IS NOT NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getScalarResult(), 'trackId');
    }
}
