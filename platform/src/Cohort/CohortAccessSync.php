<?php

namespace App\Cohort;

use App\Entity\Cohort;
use App\Entity\TrackAccess;
use App\Entity\User;
use App\Repository\TrackAccessRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Les accès qu'une cohorte financée par l'établissement ouvre à ses apprenants : un par parcours choisi, aux dates
 * de la cohorte. Tenus à jour quand un apprenant entre ou sort, et quand la cohorte change (parcours, dates, mode).
 * Seuls les accès « cohorte » de cette cohorte sont touchés : un achat ou un accès offert ne bouge jamais.
 * Retirer un accès le révoque (fin = maintenant) : la progression reste.
 */
final readonly class CohortAccessSync
{
    public function __construct(
        private TrackAccessRepository $accesses,
        private UserRepository $users,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /** L'apprenant vient d'entrer dans la cohorte (inscription avec son code, ou rattachement par l'admin). */
    public function join(User $user, Cohort $cohort): void
    {
        if ($cohort->isFundedByInstitution()) {
            foreach ($cohort->getAvailableTrackIds() as $trackId) {
                $this->open($user, $trackId, $cohort);
            }
        }
        $this->entityManager->flush();
    }

    /** L'apprenant a quitté la cohorte (changement de cohorte par l'admin). */
    public function leave(User $user, Cohort $cohort): void
    {
        $now = $this->clock->now();
        foreach ($this->accesses->findByCohort($cohort) as $access) {
            if ($access->getUser() === $user) {
                $access->revoke($now);
            }
        }
        $this->entityManager->flush();
    }

    /** Après un changement de la cohorte : parcours ajoutés ou retirés, dates, mode de financement. */
    public function sync(Cohort $cohort): void
    {
        $now = $this->clock->now();
        $selected = $cohort->isFundedByInstitution() ? $cohort->getAvailableTrackIds() : [];
        // Lu en base : la collection inverse Cohort::$users peut ne pas refléter un rattachement récent.
        $members = null === $cohort->getId() ? [] : $this->users->findBy(['cohort' => $cohort]);

        foreach ($members as $user) {
            foreach ($selected as $trackId) {
                $this->open($user, $trackId, $cohort);
            }
        }
        foreach ($this->accesses->findByCohort($cohort) as $access) {
            if (!\in_array($access->getTrackId(), $selected, true) || !\in_array($access->getUser(), $members, true)) {
                $access->revoke($now);
            }
        }
        $this->entityManager->flush();
    }

    /** Avant de supprimer la cohorte : ses accès ne doivent pas lui survivre. */
    public function revokeAll(Cohort $cohort): void
    {
        $now = $this->clock->now();
        foreach ($this->accesses->findByCohort($cohort) as $access) {
            $access->revoke($now);
        }
        $this->entityManager->flush();
    }

    private function open(User $user, string $trackId, Cohort $cohort): void
    {
        $access = null === $user->getId() || null === $cohort->getId() ? null : $this->accesses->findCohortAccess($user, $trackId, $cohort);
        if (null === $access) {
            $this->entityManager->persist(TrackAccess::forCohort($user, $trackId, $cohort));

            return;
        }
        // Rouvert s'il avait été révoqué (parcours rendu à la cohorte, apprenant revenu), aux dates actuelles.
        $access->followCohortDates($cohort);
    }
}
