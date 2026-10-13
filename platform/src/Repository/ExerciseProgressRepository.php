<?php

namespace App\Repository;

use App\Admin\ProgressSummary;
use App\Entity\ExerciseProgress;
use App\Entity\ProgressStatus;
use App\Entity\User;
use App\Entity\VerificationStatus;
use App\Leaderboard\Leaderboard;
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

    /**
     * Le classement : XP et exercices réussis par apprenant, du meilleur au moins bon. Seuls comptent les apprenants qui
     * l'ont choisi (et que l'admin n'en a pas retirés), plus $include, pour lui dire où il se placerait. Une réussite que
     * le verificateur a infirmée ne compte pas ; une réussite en attente compte déjà.
     *
     * @param \DateTimeImmutable|null $since réussites depuis cette date seulement (le classement du mois)
     * @param \DateTimeImmutable|null $until et avant celle-ci (le classement du mois précédent)
     * @param string|null             $track un seul parcours, ou Leaderboard::PRACTICE pour la Pratique
     *
     * @return list<array{userId: int, displayName: string, xp: int, exercises: int}>
     */
    public function leaderboardRows(?\DateTimeImmutable $since = null, ?User $include = null, ?\DateTimeImmutable $until = null, ?string $track = null): array
    {
        $builder = $this->createQueryBuilder('p')
            ->select('u.id AS userId', 'u.displayName AS displayName', 'SUM(p.xpEarned) AS xp', 'COUNT(p.id) AS exercises')
            ->join('p.user', 'u')
            ->andWhere('p.status = :completed')
            ->andWhere('p.verification IS NULL OR p.verification <> :failed')
            ->andWhere('u.leaderboardExcluded = false')
            ->groupBy('u.id', 'u.displayName')
            ->having('SUM(p.xpEarned) > 0')
            ->orderBy('xp', 'DESC')
            ->addOrderBy('exercises', 'DESC')
            ->addOrderBy('u.displayName', 'ASC')
            ->addOrderBy('u.id', 'ASC')
            ->setParameter('completed', ProgressStatus::Completed)
            ->setParameter('failed', VerificationStatus::Failed);
        if (null !== $include) {
            $builder->andWhere('u.leaderboardOptIn = true OR u = :include')->setParameter('include', $include);
        } else {
            $builder->andWhere('u.leaderboardOptIn = true');
        }
        if (null !== $since) {
            $builder->andWhere('p.completedAt >= :since')->setParameter('since', $since);
        }
        if (null !== $until) {
            $builder->andWhere('p.completedAt < :until')->setParameter('until', $until);
        }
        if (Leaderboard::PRACTICE === $track) {
            $builder->andWhere('p.trackId IS NULL');
        } elseif (null !== $track) {
            $builder->andWhere('p.trackId = :track')->setParameter('track', $track);
        }

        return array_map(static fn (array $row) => [
            'userId' => (int) $row['userId'],
            'displayName' => (string) $row['displayName'],
            'xp' => (int) $row['xp'],
            'exercises' => (int) $row['exercises'],
        ], $builder->getQuery()->getArrayResult());
    }

    /**
     * Les parcours où chacun de ces apprenants a réussi au moins un exercice (null : la Pratique), pour le classement.
     *
     * @param list<int> $userIds
     *
     * @return array<int, list<string|null>> par identifiant d'apprenant
     */
    public function completedTrackIdsByUsers(array $userIds): array
    {
        if ([] === $userIds) {
            return [];
        }
        $rows = $this->createQueryBuilder('p')
            ->select('DISTINCT IDENTITY(p.user) AS userId', 'p.trackId')
            ->andWhere('p.user IN (:users)')
            ->andWhere('p.status = :completed')
            ->setParameter('users', $userIds)
            ->setParameter('completed', ProgressStatus::Completed)
            ->getQuery()
            ->getArrayResult();
        $tracks = [];
        foreach ($rows as $row) {
            $tracks[(int) $row['userId']][] = $row['trackId'];
        }

        return $tracks;
    }

    /**
     * Les apprenants qui ont réussi au moins un exercice de ce parcours (l'attribution des bonus de parcours déjà
     * terminés, app:classement:bonus-parcours).
     *
     * @return list<User>
     */
    public function usersWithCompletionsIn(string $trackId): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('EXISTS (SELECT p.id FROM '.ExerciseProgress::class.' p WHERE p.user = u AND p.trackId = :track AND p.status = :completed)')
            ->setParameter('track', $trackId)
            ->setParameter('completed', ProgressStatus::Completed)
            ->getQuery()
            ->getResult();
    }

    /**
     * Les réussites à envoyer au verificateur : en attente, jamais envoyées ou envoyées avant $requestedBefore (une
     * demande perdue repart). Les plus anciennes d'abord.
     *
     * @return list<ExerciseProgress>
     */
    public function findVerificationsToRequest(\DateTimeImmutable $requestedBefore, int $limit): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.status = :completed')
            ->andWhere('p.verification = :pending')
            ->andWhere('p.verificationRequestedAt IS NULL OR p.verificationRequestedAt < :before')
            ->setParameter('completed', ProgressStatus::Completed)
            ->setParameter('pending', VerificationStatus::Pending)
            ->setParameter('before', $requestedBefore)
            ->orderBy('p.completedAt', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
