<?php

namespace App\Repository;

use App\Entity\TrackBonus;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<TrackBonus> */
class TrackBonusRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrackBonus::class);
    }

    public function findOne(User $user, string $trackId): ?TrackBonus
    {
        return $this->findOneBy(['user' => $user, 'trackId' => $trackId]);
    }

    /**
     * Les bonus de parcours par apprenant, pour le classement : mêmes apprenants que
     * ExerciseProgressRepository::leaderboardRows().
     *
     * @param \DateTimeImmutable|null $since   bonus tombés depuis cette date
     * @param \DateTimeImmutable|null $until   et avant celle-ci (le classement du mois précédent)
     * @param string|null             $trackId un seul parcours
     *
     * @return list<array{userId: int, displayName: string, xp: int}>
     */
    public function leaderboardRows(?\DateTimeImmutable $since = null, ?User $include = null, ?\DateTimeImmutable $until = null, ?string $trackId = null): array
    {
        $builder = $this->createQueryBuilder('b')
            ->select('u.id AS userId', 'u.displayName AS displayName', 'SUM(b.xp) AS xp')
            ->join('b.user', 'u')
            ->andWhere('u.leaderboardExcluded = false')
            ->groupBy('u.id', 'u.displayName');
        if (null !== $include) {
            $builder->andWhere('u.leaderboardOptIn = true OR u = :include')->setParameter('include', $include);
        } else {
            $builder->andWhere('u.leaderboardOptIn = true');
        }
        if (null !== $since) {
            $builder->andWhere('b.awardedAt >= :since')->setParameter('since', $since);
        }
        if (null !== $until) {
            $builder->andWhere('b.awardedAt < :until')->setParameter('until', $until);
        }
        if (null !== $trackId) {
            $builder->andWhere('b.trackId = :track')->setParameter('track', $trackId);
        }

        return array_map(static fn (array $row) => [
            'userId' => (int) $row['userId'],
            'displayName' => (string) $row['displayName'],
            'xp' => (int) $row['xp'],
        ], $builder->getQuery()->getArrayResult());
    }
}
