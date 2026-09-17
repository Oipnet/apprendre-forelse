<?php

namespace App\Account;

use App\Content\ContentRepository;
use App\Content\Exercise;
use App\Content\Track;
use App\Content\TrackVisibility;
use App\Entity\ExerciseProgress;
use App\Entity\ProgressStatus;
use App\Entity\User;
use App\Payment\TrackOfferFactory;
use App\Repository\ExerciseProgressRepository;
use App\Repository\TrackAccessRepository;
use Psr\Clock\ClockInterface;

/**
 * Où en est l'apprenant, pour sa page « Mon compte » : chaque parcours qu'il peut voir, d'où le reprendre,
 * et les chiffres de l'en-tête.
 */
final readonly class AccountProgress
{
    public const string STARTED = 'started';
    public const string DONE = 'done';
    /** Pas commencé, mais tout le parcours est ouvert (accès, cohorte, rôle). */
    public const string OPEN = 'open';
    /** Pas commencé, et gratuit pour tout compte. */
    public const string FREE = 'free';
    public const string NOT_STARTED = 'not_started';

    public function __construct(
        private ContentRepository $content,
        private TrackVisibility $visibility,
        private ExerciseProgressRepository $progress,
        private TrackAccessRepository $accesses,
        private TrackOfferFactory $offers,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Les parcours visibles : ceux en cours, le plus récemment travaillé d'abord, puis ceux déjà ouverts,
     * puis les autres, dans l'ordre du site. Les parcours terminés viennent en dernier.
     *
     * @return list<array{track: Track, state: string, completed: int, total: int, xp: int, next: ?Exercise, lastActivity: ?\DateTimeImmutable}>
     */
    public function tracks(User $user): array
    {
        $rows = [];
        foreach (array_values($this->visibility->tracks()) as $rank => $track) {
            $progress = $this->progress->findByTrack($user, $track->id);
            $completed = array_filter($progress, static fn (ExerciseProgress $p) => $p->isCompleted());
            $total = \count($track->exerciseIds());
            $done = \count(array_intersect_key($completed, array_flip($track->exerciseIds())));
            // Reprendre au premier exercice pas encore réussi, dans l'ordre du parcours.
            $nextId = array_find($track->exerciseIds(), static fn (string $id) => !isset($completed[$id]));
            $dates = array_map(static fn (ExerciseProgress $p) => $p->getUpdatedAt(), $progress);
            $offer = $this->offers->create($track, $user);

            $rows[] = [
                'track' => $track,
                'state' => match (true) {
                    $total > 0 && $done === $total => self::DONE,
                    [] !== $progress => self::STARTED,
                    $offer->quote->isFree() => self::FREE,
                    $offer->fullAccess => self::OPEN,
                    default => self::NOT_STARTED,
                },
                'completed' => $done,
                'total' => $total,
                'xp' => array_sum(array_map(static fn (ExerciseProgress $p) => $p->getXpEarned(), $progress)),
                'next' => null === $nextId ? null : $this->content->findExercise($track->id, $nextId),
                'lastActivity' => $dates ? max($dates) : null,
                'rank' => $rank,
            ];
        }
        $weight = [self::STARTED => 0, self::OPEN => 1, self::FREE => 2, self::NOT_STARTED => 3, self::DONE => 4];
        usort($rows, static fn (array $a, array $b) => [$weight[$a['state']], $b['lastActivity'], $a['rank']] <=> [$weight[$b['state']], $a['lastActivity'], $b['rank']]);

        return array_map(static function (array $row) {
            unset($row['rank']);

            return $row;
        }, $rows);
    }

    /** Exercices réussis, parcours et Pratique confondus. */
    public function completedExercises(User $user): int
    {
        return $this->progress->count(['user' => $user, 'status' => ProgressStatus::Completed]);
    }

    /** Exercices de Pratique réussis. */
    public function practiceCompleted(User $user): int
    {
        return \count(array_filter($this->progress->findPractice($user), static fn (ExerciseProgress $p) => $p->isCompleted()));
    }

    /** Accès aux parcours en cours de validité. */
    public function activeAccesses(User $user): int
    {
        return \count($this->accesses->findActiveTrackIds($user, $this->clock->now()));
    }
}
