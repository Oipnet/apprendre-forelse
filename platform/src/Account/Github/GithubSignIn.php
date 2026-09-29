<?php

namespace App\Account\Github;

use App\Account\UserRegistration;
use App\Entity\ExternalIdentity;
use App\Entity\User;
use App\Repository\ExternalIdentityRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Les règles de la connexion avec GitHub : retrouver le compte, le lier, ou en créer un.
 *
 * Un compte GitHub est d'abord cherché par son identifiant. À défaut, il est rattaché au compte qui a la même
 * adresse, à condition que GitHub l'ait vérifiée ET que le compte l'ait confirmée : sans la première, n'importe qui
 * pourrait déclarer l'adresse d'un autre sur GitHub ; sans la seconde, quelqu'un qui aurait inscrit l'adresse d'un
 * autre, avec un mot de passe à lui, récupérerait le compte dès que le vrai titulaire passerait par GitHub.
 */
final readonly class GithubSignIn
{
    public function __construct(
        private ExternalIdentityRepository $identities,
        private UserRepository $users,
        private UserRegistration $registration,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /** @param User|null $linkTo le compte connecté qui demande à lier ce compte GitHub, ou null pour se connecter */
    public function resolve(GithubProfile $profile, ?User $linkTo = null): GithubSignInResult
    {
        $identity = $this->identities->findOneByProviderId(ExternalIdentity::GITHUB, $profile->id);
        if (null !== $identity) {
            if (null !== $linkTo && $identity->getUser()->getId() !== $linkTo->getId()) {
                return new GithubSignInResult(GithubSignInOutcome::LinkedElsewhere);
            }
            $identity->setUsername($profile->login);
            $this->entityManager->flush();

            return new GithubSignInResult(null !== $linkTo ? GithubSignInOutcome::Linked : GithubSignInOutcome::SignedIn, $identity->getUser());
        }

        if (null !== $linkTo) {
            return $this->link($linkTo, $profile, GithubSignInOutcome::Linked);
        }

        foreach ($profile->verifiedEmails as $email) {
            $user = $this->users->findOneByEmail($email);
            if (null !== $user) {
                return $user->isEmailVerified()
                    ? $this->link($user, $profile, GithubSignInOutcome::SignedIn)
                    : new GithubSignInResult(GithubSignInOutcome::UnverifiedAccount);
            }
        }

        return new GithubSignInResult(null === $profile->primaryEmail() ? GithubSignInOutcome::NoVerifiedEmail : GithubSignInOutcome::RegistrationNeeded);
    }

    /**
     * Crée le compte d'un nouvel apprenant venu de GitHub : sans mot de passe, adresse confirmée par GitHub, mêmes
     * règles que l'inscription pour la cohorte.
     */
    public function register(GithubProfile $profile, string $displayName, ?string $code): User
    {
        $email = $profile->primaryEmail() ?? throw new \LogicException('Aucune adresse vérifiée par GitHub.');
        $user = (new User())->setDisplayName(trim($displayName))->confirmEmail($email, $this->clock->now());
        $this->registration->registerWithoutPassword($user, $code);
        $this->link($user, $profile, GithubSignInOutcome::SignedIn);

        return $user;
    }

    /** Délie GitHub du compte ; refusé s'il n'a pas de mot de passe : il ne pourrait plus se connecter. */
    public function unlink(User $user): bool
    {
        $identity = $this->identities->findOneByUser($user, ExternalIdentity::GITHUB);
        if (null === $identity || !$user->hasPassword()) {
            return false;
        }
        $this->entityManager->remove($identity);
        $this->entityManager->flush();

        return true;
    }

    private function link(User $user, GithubProfile $profile, GithubSignInOutcome $outcome): GithubSignInResult
    {
        if (null !== $this->identities->findOneByUser($user, ExternalIdentity::GITHUB)) {
            return new GithubSignInResult(GithubSignInOutcome::OtherGithubAccount);
        }
        $this->entityManager->persist(new ExternalIdentity($user, ExternalIdentity::GITHUB, $profile->id, $profile->login, $this->clock->now()));
        $this->entityManager->flush();

        return new GithubSignInResult($outcome, $user);
    }
}
