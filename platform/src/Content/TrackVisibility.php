<?php

namespace App\Content;

use App\Entity\Cohort;
use App\Entity\User;
use App\Repository\CohortRepository;
use App\Repository\ExerciseProgressRepository;
use App\Repository\TrackAccessRepository;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Les parcours que l'utilisateur courant peut voir : absents des listes et introuvables (404) sinon, jamais
 * conseillés comme parcours suivant.
 *
 * - Un parcours en préparation (`visibility: admin` dans track.yaml) n'existe que pour les administrateurs,
 *   et pour les apprenants et les chefs d'une cohorte qui l'a explicitement choisi. Il reste vérifié par content:check,
 *   modifiable dans l'atelier et compté dans les exports.
 * - Un apprenant rattaché à une cohorte ne voit que les parcours qu'elle propose (tous, tant qu'aucun n'a été
 *   choisi), plus ceux qu'il a déjà commencés (retirer un parcours à une cohorte n'interrompt personne)
 *   et ceux auxquels il a un accès actif (un parcours acheté hors de la sélection de sa cohorte).
 * - Un chef de cohorte voit en plus ce que proposent les cohortes qu'il encadre (comme TrackAccessChecker).
 * - Sans cohorte (visiteur, inscription libre), rien ne change.
 *
 * Les pages des parcours sont dans la langue de la page : sous /en, un parcours n'existe que traduit (findHere()),
 * et les listes montrent sa traduction quand il en a une, le français sinon (localize()).
 */
final class TrackVisibility implements ResetInterface
{
    /** @var array<int, list<string>> parcours commencés, par apprenant : lus une fois par requête, au besoin */
    private array $startedTrackIds = [];
    /** @var array<int, list<string>> parcours à accès actif, par apprenant */
    private array $accessibleTrackIds = [];
    /** @var array<int, list<Cohort>> cohortes encadrées, par chef */
    private array $chefCohorts = [];

    public function __construct(
        private readonly ContentRepository $content,
        private readonly Security $security,
        private readonly ExerciseProgressRepository $progress,
        private readonly TrackAccessRepository $accesses,
        private readonly CohortRepository $cohorts,
        private readonly ClockInterface $clock,
        private readonly ?LocaleSwitcher $locale = null,
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
            return !$track->isRestricted() || ($user instanceof User && $this->chefOffers($user, $track));
        }

        return self::isOfferedBy([$cohort], $track) || $this->chefOffers($user, $track) || $this->hasStarted($user, $track->id) || $this->hasAccess($user, $track->id);
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

    /** La langue de la page ; une variante régionale (en_GB) compte pour sa langue. */
    public function locale(): string
    {
        return null === $this->locale ? Exercise::LOCALE : explode('_', $this->locale->getLocale())[0];
    }

    /** Le parcours dans la langue de la page, s'il y est traduit et si l'utilisateur courant peut le voir. */
    public function findHere(string $trackId): ?Track
    {
        return null === $this->find($trackId) ? null : $this->content->findTrack($trackId, $this->locale());
    }

    /** Le parcours dans la langue de la page s'il y est traduit, tel quel sinon (une liste le montre en français). */
    public function localize(Track $track): Track
    {
        return $this->content->findTrack($track->id, $this->locale()) ?? $track;
    }

    /** @return array<string, Track> les parcours visibles, chacun dans la langue de la page s'il y est traduit */
    public function tracksHere(): array
    {
        return array_map($this->localize(...), $this->tracks());
    }

    /**
     * Les parcours en préparation que l'utilisateur courant ne voit pas : ils s'annoncent (« bientôt »), sans lien,
     * leur page n'existe pas pour lui. Dans l'ordre du catalogue (order, puis titre).
     *
     * @return list<Track>
     */
    public function upcoming(): array
    {
        $upcoming = array_values(array_filter($this->content->tracks(), fn (Track $track) => $track->isRestricted() && !$this->isVisible($track)));
        usort($upcoming, static fn (Track $a, Track $b) => [$a->order ?? \PHP_INT_MAX, $a->title] <=> [$b->order ?? \PHP_INT_MAX, $b->title]);

        return $upcoming;
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
        $this->chefCohorts = [];
    }

    /** Une des cohortes que l'utilisateur encadre propose le parcours. */
    private function chefOffers(User $user, Track $track): bool
    {
        if (null === $user->getId() || !$this->security->isGranted(User::ROLE_CHEF_COHORTE)) {
            return false;
        }
        $this->chefCohorts[$user->getId()] ??= $this->cohorts->findByChef($user);

        return self::isOfferedBy($this->chefCohorts[$user->getId()], $track);
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
