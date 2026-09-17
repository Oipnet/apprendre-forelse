<?php

namespace App\Security;

use App\Content\Chapter;
use App\Content\ContentRepository;
use App\Content\Exercise;
use App\Content\Track;
use App\Content\TrackVisibility;
use App\Entity\User;
use App\Repository\CohortRepository;
use App\Repository\TrackAccessRepository;
use App\Repository\TrackPricingRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * La seule réponse à « cet apprenant peut-il ouvrir ce chapitre ? », quelle que soit la façon dont l'accès a été
 * obtenu. Toute page ou API de contenu (exercice, progression, mentor, fiche, livret) passe par lui.
 *
 * Règles, dans l'ordre :
 *  1. le premier chapitre d'un parcours public est ouvert à tous, avec ou sans compte ;
 *  2. un administrateur ou un auteur (qui relit le contenu) ouvre tout ;
 *  3. sans compte, rien d'autre ;
 *  4. un parcours gratuit (sans tarif, ou à 0 €) est ouvert à tout compte ;
 *  5. un chef de cohorte ouvre les parcours que proposent ses cohortes ;
 *  6. sinon, il faut un accès actif (achat, cohorte, offert : TrackAccess).
 *
 * La visibilité (parcours en préparation, sélection de la cohorte) se vérifie avant, par TrackVisibility (404).
 * La progression n'est jamais concernée : on la garde, qu'on ait accès ou non.
 */
final class TrackAccessChecker implements ResetInterface
{
    /** @var array<int, list<string>> parcours à accès actif, par apprenant : lus une fois par requête */
    private array $activeTrackIds = [];

    public function __construct(
        private readonly TrackAccessRepository $accesses,
        private readonly TrackPricingRepository $pricings,
        private readonly CohortRepository $cohorts,
        private readonly ContentRepository $content,
        private readonly RoleHierarchyInterface $roleHierarchy,
        private readonly ClockInterface $clock,
    ) {
    }

    public function canAccess(?User $user, Track $track, Chapter $chapter): bool
    {
        if (self::isFreeChapter($track, $chapter)) {
            return true;
        }

        return $this->hasFullAccess($user, $track);
    }

    /** Tout le parcours est ouvert (règles 2 à 6) : ce qui distingue « Continuer » d'« Acheter ». */
    public function hasFullAccess(?User $user, Track $track): bool
    {
        return match (true) {
            null === $user => false,
            $this->isStaff($user) => true,
            !$this->pricings->isPaid($track->id) => true,
            $this->chefOffers($user, $track) => true,
            default => $this->hasActiveAccess($user, $track->id),
        };
    }

    /** Un exercice de parcours suit la règle de son chapitre ; la Pratique demande un compte. */
    public function canAccessExercise(?User $user, Exercise $exercise): bool
    {
        if (null === $exercise->trackId) {
            return null !== $user;
        }
        $track = $this->content->findTrack($exercise->trackId);
        $chapter = null === $track ? null : $this->content->chapterOf($exercise);

        return null !== $track && null !== $chapter && $this->canAccess($user, $track, $chapter);
    }

    /** Le premier chapitre d'un parcours public : la porte d'entrée, sans compte. */
    public static function isFreeChapter(Track $track, Chapter $chapter): bool
    {
        return !$track->isRestricted() && ($track->chapters[0] ?? null)?->id === $chapter->id;
    }

    /** Un accès actif (achat, cohorte, offert), sans compter le rôle ni la gratuité : ce qui interdit de racheter. */
    public function hasActiveAccess(User $user, string $trackId): bool
    {
        if (null === $user->getId()) {
            return false;
        }
        $this->activeTrackIds[$user->getId()] ??= $this->accesses->findActiveTrackIds($user, $this->clock->now());

        return \in_array($trackId, $this->activeTrackIds[$user->getId()], true);
    }

    public function reset(): void
    {
        $this->activeTrackIds = [];
    }

    private function isStaff(User $user): bool
    {
        return \in_array(User::ROLE_AUTEUR, $this->roleHierarchy->getReachableRoleNames($user->getRoles()), true);
    }

    private function chefOffers(User $user, Track $track): bool
    {
        if (!\in_array(User::ROLE_CHEF_COHORTE, $this->roleHierarchy->getReachableRoleNames($user->getRoles()), true)) {
            return false;
        }

        return TrackVisibility::isOfferedBy($this->cohorts->findByChef($user), $track);
    }
}
