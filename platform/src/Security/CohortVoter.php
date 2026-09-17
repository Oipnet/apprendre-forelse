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
    /** Choisir les parcours que la cohorte propose. */
    public const string MANAGE_PARCOURS = 'COHORT_MANAGE_PARCOURS';

    public function __construct(private readonly AccessDecisionManagerInterface $accessDecisionManager)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::MANAGE_PARCOURS], true) && $subject instanceof Cohort;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
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
