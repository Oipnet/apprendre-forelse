<?php

namespace App\Security;

use App\Entity\Cohort;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Qui peut suivre une cohorte et choisir ses parcours : l'administrateur, pour toutes ; un chef de cohorte,
 * pour les siennes seulement. Chaque page de l'espace /cohorte/{id} passe par ce voter, en plus de access_control.
 *
 * @extends Voter<string, Cohort>
 */
final class CohortVoter extends Voter
{
    /** Voir la cohorte : effectif, lien d'invitation, avancement. */
    public const string VIEW = 'COHORT_VIEW';
    /** Choisir les parcours que la cohorte propose, son effectif, ses soutenances : pas pour une classe gratuite. */
    public const string MANAGE_PARCOURS = 'COHORT_MANAGE_PARCOURS';
    /** Choisir les exercices de la Pratique d'une classe gratuite. */
    public const string MANAGE_PRACTICE = 'COHORT_MANAGE_PRACTICE';

    public function __construct(private readonly AccessDecisionManagerInterface $accessDecisionManager)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::MANAGE_PARCOURS, self::MANAGE_PRACTICE], true) && $subject instanceof Cohort;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        // Une classe gratuite suit des exercices de la Pratique, rien d'autre ; une cohorte classique, des parcours.
        if (self::MANAGE_PARCOURS === $attribute && $subject->isFreeClass()) {
            $vote?->addReason('Une classe gratuite ne propose pas de parcours.');

            return false;
        }
        if (self::MANAGE_PRACTICE === $attribute && !$subject->isFreeClass()) {
            $vote?->addReason('Seule une classe gratuite suit des exercices de la Pratique.');

            return false;
        }
        if ($this->accessDecisionManager->decide($token, [User::ROLE_ADMIN])) {
            return true;
        }
        $user = $token->getUser();
        if (!$user instanceof User || !$this->accessDecisionManager->decide($token, [User::ROLE_CHEF_COHORTE])) {
            $vote?->addReason('Réservé aux chefs de cohorte.');

            return false;
        }
        if (!$subject->isChef($user)) {
            $vote?->addReason('Cette cohorte a d\'autres chefs.');

            return false;
        }

        return true;
    }
}
