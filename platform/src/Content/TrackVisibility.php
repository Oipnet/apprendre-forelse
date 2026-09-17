<?php

namespace App\Content;

use App\Entity\Cohort;
use App\Entity\User;
use App\Repository\ExerciseProgressRepository;
use App\Repository\TrackAccessRepository;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Les parcours que l'utilisateur courant peut voir : absents des listes et introuvables (404) sinon, jamais
 * conseillés comme parcours suivant.
 *
 * - Un parcours en préparation (`visibility: admin` dans track.yaml) n'existe que pour les administrateurs,
 *   et pour les apprenants d'une cohorte qui l'a explicitement choisi. Il reste vérifié par content:check,
 *   modifiable dans l'atelier et compté dans les exports.
 * - Un apprenant rattaché à une cohorte ne voit que les parcours qu'elle propose (tous, tant qu'aucun n'a été
 *   choisi), plus ceux qu'il a déjà commencés (retirer un parcours à une cohorte n'interrompt personne)
 *   et ceux auxquels il a un accès actif (un parcours acheté hors de la sélection de sa cohorte).
 * - Sans cohorte (visiteur, inscription libre), rien ne change.
 */
final class TrackVisibility implements ResetInterface
{
    /** @var array<int, list<string>> parcours commencés, par apprenant : lus une fois par requête, au besoin */
    private array $startedTrackIds = [];
    /** @var array<int, list<string>> parcours à accès actif, par apprenant */
    private array $accessibleTrackIds = [];

    public function __construct(
        private readonly ContentRepository $content,
        private readonly Security $security,
        private readonly ExerciseProgressRepository $progress,
        private readonly TrackAccessRepository $accesses,
        private readonly ClockInterface $clock,
    ) {
    }

    public function isVisible(Track $track): bool
    {
        if ($this->security->isGranted(User::ROLE_ADMIN)) {
            return true;
        }
        $user = $this->security->getUser();
        $cohort = $user instanceof User ? $user->getCohort() : null;
        if (null === $cohort) {
            return !$track->isRestricted();
        }

        return self::isOfferedBy([$cohort], $track) || $this->hasStarted($user, $track->id) || $this->hasAccess($user, $track->id);
    }

    /**
     * Le parcours est proposé par au moins une de ces cohortes. Un parcours en préparation doit y avoir été
     * choisi : l'absence de sélection n'ouvre que les parcours publics.
     *
     * @param iterable<Cohort> $cohorts
     */
    public static function isOfferedBy(iterable $cohorts, Track $track): bool
    {
        foreach ($cohorts as $cohort) {
            if ($track->isRestricted() ? $cohort->selectsTrack($track->id) : $cohort->offersTrack($track->id)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, Track> les parcours que l'utilisateur courant peut voir */
    public function tracks(): array
    {
        return array_filter($this->content->tracks(), $this->isVisible(...));
    }

    /** Le parcours, s'il existe et si l'utilisateur courant peut le voir. */
    public function find(string $trackId): ?Track
    {
        $track = $this->content->findTrack($trackId);

        return null !== $track && $this->isVisible($track) ? $track : null;
    }

    public function nextTrack(Track $track): ?Track
    {
        $next = $this->content->nextTrack($track);

        return null !== $next && $this->isVisible($next) ? $next : null;
    }

    public function reset(): void
    {
        $this->startedTrackIds = [];
        $this->accessibleTrackIds = [];
    }

    private function hasAccess(User $user, string $trackId): bool
    {
        $this->accessibleTrackIds[(int) $user->getId()] ??= $this->accesses->findActiveTrackIds($user, $this->clock->now());

        return \in_array($trackId, $this->accessibleTrackIds[(int) $user->getId()], true);
    }

    private function hasStarted(User $user, string $trackId): bool
    {
        $this->startedTrackIds[(int) $user->getId()] ??= $this->progress->findStartedTrackIds($user);

        return \in_array($trackId, $this->startedTrackIds[(int) $user->getId()], true);
    }
}
