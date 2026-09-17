<?php

namespace App\Admin;

use App\Entity\Cohort;
use App\Entity\User;

final readonly class CohortStats
{
    /**
     * @param list<User>       $users
     * @param list<TrackStats> $tracks
     */
    public function __construct(
        /** Clé d'URL (code d'invitation, ou « - » sans cohorte). */
        public string $key,
        public ?Cohort $cohort,
        public array $users,
        public array $tracks,
        public int $feedbacks,
        public int $unhandledFeedbacks,
    ) {
    }

    public function label(): string
    {
        return $this->cohort?->getName() ?? 'Sans cohorte';
    }

    public function completedExercises(): int
    {
        return array_sum(array_map(static fn (TrackStats $t) => $t->completed(), $this->tracks));
    }
}
