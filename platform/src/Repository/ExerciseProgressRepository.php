<?php

namespace App\Repository;

use App\Admin\ProgressSummary;
use App\Entity\ExerciseProgress;
use App\Entity\ProgressStatus;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
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

    /**
     * La progression de l'apprenant sur l'exercice, créée si besoin. Sûr face à une création simultanée (premier
     * brouillon et première réussite dans la même seconde) : la ligne est insérée par un INSERT … ON CONFLICT DO NOTHING
     * qui laisse gagner l'autre requête au lieu d'échouer sur la contrainte unique, puis relue. Les valeurs de départ
     * sont celles d'une ExerciseProgress neuve.
     */
    public function findOrCreate(User $user, ?string $trackId, string $exerciseId): ExerciseProgress
    {
        $existing = $this->findOne($user, $trackId, $exerciseId);
        if (null !== $existing) {
            return $existing;
        }

        $entityManager = $this->getEntityManager();
        $connection = $entityManager->getConnection();
        $metadata = $entityManager->getClassMetadata(ExerciseProgress::class);
        $fresh = new ExerciseProgress($user, $trackId, $exerciseId);
        $columns = [$metadata->getSingleAssociationJoinColumnName('user') => $user->getId()];
        $types = [$metadata->getSingleAssociationJoinColumnName('user') => Types::INTEGER];
        foreach ($metadata->getFieldNames() as $field) {
            if ($metadata->isIdentifier($field)) {
                continue;
            }
            $value = $metadata->getFieldValue($fresh, $field);
            $column = $metadata->getColumnName($field);
            $columns[$column] = $value instanceof \BackedEnum ? $value->value : $value;
            $types[$column] = $metadata->getTypeOfField($field) ?? Types::STRING;
        }
        $quoted = array_map($connection->quoteSingleIdentifier(...), array_keys($columns));
        $connection->executeStatement(
            sprintf('INSERT INTO %s (%s) VALUES (%s) ON CONFLICT DO NOTHING', $metadata->getTableName(), implode(', ', $quoted), implode(', ', array_fill(0, \count($columns), '?'))),
            array_values($columns),
            array_values($types),
        );

        return $this->findOne($user, $trackId, $exerciseId) ?? throw new \LogicException(sprintf('Progression « %s » introuvable juste après sa création.', $exerciseId));
    }

    /** @return array<string, ExerciseProgress> progression en Pratique, par identifiant d'exercice */
    public function findPractice(User $user): array
    {
        return $this->findByTrack($user, null);
    }

    /**
     * L'état de la progression d'un groupe d'apprenants dans les parcours (tableaux de bord), sans la Pratique. Requête
     * partielle : ni les brouillons de code (files) ni la revue du mentor, qui pèsent et ne s'affichent pas.
     *
     * @param list<User> $users
     *
     * @return list<ProgressSummary>
     */
    public function summariesByUsers(array $users): array
    {
        if (!$users) {
            return [];
        }
        $rows = $this->createQueryBuilder('p')
            ->select('IDENTITY(p.user) AS userId', 'p.trackId', 'p.exerciseId', 'p.status', 'p.hintsUsed', 'p.startedAt', 'p.completedAt')
            ->andWhere('p.user IN (:users)')
            ->andWhere('p.trackId IS NOT NULL')
            ->setParameter('users', $users)
            ->orderBy('p.id')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row) => new ProgressSummary(
            (int) $row['userId'],
            $row['trackId'],
            $row['exerciseId'],
            $row['status'] instanceof ProgressStatus ? $row['status'] : ProgressStatus::from($row['status']),
            $row['hintsUsed'],
            $row['startedAt'],
            $row['completedAt'],
        ), $rows);
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
