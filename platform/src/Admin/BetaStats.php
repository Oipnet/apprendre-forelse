<?php

namespace App\Admin;

use App\Content\ContentRepository;
use App\Entity\Cohort;
use App\Entity\Feedback;
use App\Entity\User;
use App\Repository\CohortRepository;
use App\Repository\ExerciseProgressRepository;
use App\Repository\FeedbackRepository;
use App\Repository\UserRepository;

/**
 * Chiffres du tableau de bord : par cohorte (code d'invitation), effectif, avancement par chapitre
 * et retours en attente. Volumes d'une bêta : tout est calculé en mémoire, sans requête agrégée.
 */
final readonly class BetaStats
{
    /** Valeur d'URL pour les comptes sans cohorte. */
    public const string NO_COHORT = '-';

    public function __construct(
        private UserRepository $users,
        private CohortRepository $cohorts,
        private ExerciseProgressRepository $progress,
        private FeedbackRepository $feedbacks,
        private ContentRepository $content,
    ) {
    }

    /** @return list<CohortStats> toutes les cohortes (même vides) par nom, puis « sans cohorte » s'il y a des comptes libres */
    public function cohorts(): array
    {
        $byCohort = [];
        foreach ($this->cohorts->findAllOrdered() as $cohort) {
            $byCohort[$cohort->getCode()] = [$cohort, []];
        }
        $byCohort[self::NO_COHORT] = [null, []];
        foreach ($this->users->findByCohort(null) as $user) {
            $byCohort[$user->getCohort()?->getCode() ?? self::NO_COHORT][1][] = $user;
        }
        if (!$byCohort[self::NO_COHORT][1]) {
            unset($byCohort[self::NO_COHORT]);
        }

        return array_values(array_map(fn (array $entry) => $this->statsOf($entry[0], $entry[1], cohortTracksOnly: true), $byCohort));
    }

    /** @param string $key code de la cohorte, ou « - » pour les comptes sans cohorte */
    public function cohort(string $key): ?CohortStats
    {
        if (self::NO_COHORT === $key) {
            $users = array_values(array_filter($this->users->findByCohort(null), static fn (User $u) => null === $u->getCohort()));

            return $users ? $this->statsOf(null, $users) : null;
        }
        $cohort = $this->cohorts->findOneByCode($key);

        return $cohort ? $this->of($cohort) : null;
    }

    /** Avancement d'une cohorte, limité aux parcours qu'elle propose. */
    public function of(Cohort $cohort): CohortStats
    {
        return $this->statsOf($cohort, $this->users->findByCohort($cohort->getCode()), cohortTracksOnly: true);
    }

    /**
     * Apprenants de la cohorte qui ont commencé un parcours sans le terminer : à prévenir avant de le retirer.
     *
     * @return array<string, int> nombre d'apprenants en cours, par parcours (absent : personne)
     */
    public function learnersInProgress(Cohort $cohort): array
    {
        $started = [];
        foreach ($this->progress->findByUsers($this->users->findByCohort($cohort->getCode())) as $progress) {
            $started[$progress->getTrackId()][$progress->getUser()->getId()][$progress->getExerciseId()] = $progress->isCompleted();
        }

        $counts = [];
        foreach ($started as $trackId => $byUser) {
            $exercises = \count($this->content->findTrack($trackId)?->exerciseIds() ?? []);
            $inProgress = \count(array_filter($byUser, static fn (array $done) => \count(array_filter($done)) < $exercises));
            if ($inProgress) {
                $counts[$trackId] = $inProgress;
            }
        }

        return $counts;
    }

    /** Avancement d'un seul apprenant (fiche du tableau de bord) : une « cohorte » d'une personne, tous parcours confondus. */
    public function learner(User $user): CohortStats
    {
        return $this->statsOf($user->getCohort(), [$user]);
    }

    /**
     * @param list<User> $users
     * @param bool       $cohortTracksOnly seulement les parcours que la cohorte propose (tous s'il n'y a pas de cohorte ou de sélection)
     */
    private function statsOf(?Cohort $cohort, array $users, bool $cohortTracksOnly = false): CohortStats
    {
        $key = $cohort?->getCode() ?? self::NO_COHORT;
        $ids = array_map(static fn (User $u) => $u->getId(), $users);
        $cells = [];
        foreach ($this->progress->findByUsers($users) as $progress) {
            $cells[$progress->getUser()->getId()][$progress->getTrackId()][$progress->getExerciseId()] = $progress;
        }

        $tracks = [];
        foreach ($this->content->tracks() as $track) {
            if ($cohortTracksOnly && $cohort && !$cohort->offersTrack($track->id)) {
                continue;
            }
            $chapters = [];
            foreach ($track->chapters as $chapter) {
                $completed = 0;
                foreach ($ids as $id) {
                    foreach ($chapter->exerciseIds as $exerciseId) {
                        $completed += ($cells[$id][$track->id][$exerciseId] ?? null)?->isCompleted() ? 1 : 0;
                    }
                }
                $possible = \count($users) * \count($chapter->exerciseIds);
                $chapters[] = new ChapterStats($chapter->title, $chapter->exerciseIds, $completed, $possible ? $completed / $possible : 0.0);
            }
            $tracks[] = new TrackStats($track->id, $track->title, $chapters, $cells, array_map(fn (string $id) => $this->content->findExercise($track->id, $id)?->title ?? $id, array_combine($track->exerciseIds(), $track->exerciseIds())));
        }

        $feedbacks = array_filter($this->feedbacks->findByCohort($cohort?->getCode()), static fn (Feedback $f) => \in_array($f->getUser()->getId(), $ids, true));
        $unhandled = \count(array_filter($feedbacks, static fn (Feedback $f) => !$f->isHandled()));

        return new CohortStats($key, $cohort, $users, $tracks, \count($feedbacks), $unhandled);
    }
}
