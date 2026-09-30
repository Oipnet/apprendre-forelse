<?php

namespace App\Account\Oauth;

use App\Account\UserRegistration;
use App\Entity\ExternalIdentity;
use App\Entity\User;
use App\Repository\ExternalIdentityRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Les règles de la connexion avec un fournisseur (GitHub, Google, LinkedIn) : retrouver le compte, le lier, ou en
 * créer un. Les mêmes pour tous.
 *
 * Un compte chez le fournisseur est d'abord cherché par son identifiant. À défaut, il est rattaché au compte qui a la
 * même adresse, à condition que le fournisseur l'ait vérifiée ET que le compte l'ait confirmée : sans la première,
 * n'importe qui pourrait déclarer l'adresse d'un autre chez le fournisseur ; sans la seconde, quelqu'un qui aurait
 * inscrit l'adresse d'un autre, avec un mot de passe à lui, récupérerait le compte dès que le vrai titulaire
 * passerait par le fournisseur.
 */
final readonly class ExternalSignIn
{
    public function __construct(
        private ExternalIdentityRepository $identities,
        private UserRepository $users,
        private UserRegistration $registration,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /** @param User|null $linkTo le compte connecté qui demande à lier ce compte du fournisseur, ou null pour se connecter */
    public function resolve(ExternalProfile $profile, ?User $linkTo = null): SignInResult
    {
        $identity = $this->identities->findOneByProviderId($profile->provider, $profile->id);
        if (null !== $identity) {
            if (null !== $linkTo && $identity->getUser()->getId() !== $linkTo->getId()) {
                return new SignInResult(SignInOutcome::LinkedElsewhere);
            }
            $identity->setUsername($profile->username);
            $this->entityManager->flush();

            return new SignInResult(null !== $linkTo ? SignInOutcome::Linked : SignInOutcome::SignedIn, $identity->getUser());
        }

        if (null !== $linkTo) {
            return $this->link($linkTo, $profile, SignInOutcome::Linked);
        }

        foreach ($profile->verifiedEmails as $email) {
            $user = $this->users->findOneByEmail($email);
            if (null !== $user) {
                return $user->isEmailVerified()
                    ? $this->link($user, $profile, SignInOutcome::SignedIn)
                    : new SignInResult(SignInOutcome::UnverifiedAccount);
            }
        }

        return new SignInResult(null === $profile->primaryEmail() ? SignInOutcome::NoVerifiedEmail : SignInOutcome::RegistrationNeeded);
    }

    /**
     * Le compte connecté confirme son identité (compte sans mot de passe) : seulement avec un compte du fournisseur
     * DÉJÀ lié au sien. En lier un nouveau ne prouverait rien : une session volée suffirait à lier le sien.
     */
    public function confirms(ExternalProfile $profile, User $current): bool
    {
        $identity = $this->identities->findOneByProviderId($profile->provider, $profile->id);
        if (null === $identity || $identity->getUser()->getId() !== $current->getId()) {
            return false;
        }
        $identity->setUsername($profile->username);
        $this->entityManager->flush();

        return true;
    }

    /**
     * Crée le compte d'un nouvel apprenant venu d'un fournisseur : sans mot de passe, adresse confirmée par le
     * fournisseur, mêmes règles que l'inscription pour la cohorte.
     */
    public function register(ExternalProfile $profile, string $displayName, ?string $code): User
    {
        $email = $profile->primaryEmail() ?? throw new \LogicException('Aucune adresse vérifiée par le fournisseur.');
        $user = (new User())->setDisplayName(trim($displayName))->confirmEmail($email, $this->clock->now());
        $this->registration->registerWithoutPassword($user, $code);
        $this->link($user, $profile, SignInOutcome::SignedIn);

        return $user;
    }

    /**
     * Délie ce fournisseur du compte ; refusé s'il ne lui resterait aucun moyen de se connecter (ni mot de passe, ni
     * autre fournisseur lié).
     */
    public function unlink(User $user, string $provider): bool
    {
        $identity = $this->identities->findOneByUser($user, $provider);
        if (null === $identity || (!$user->hasPassword() && \count($this->identities->findByUser($user)) < 2)) {
            return false;
        }
        $this->entityManager->remove($identity);
        $this->entityManager->flush();

        return true;
    }

    private function link(User $user, ExternalProfile $profile, SignInOutcome $outcome): SignInResult
    {
        if (null !== $this->identities->findOneByUser($user, $profile->provider)) {
            return new SignInResult(SignInOutcome::OtherProviderAccount);
        }
        $this->entityManager->persist(new ExternalIdentity($user, $profile->provider, $profile->id, $profile->username, $this->clock->now()));
        $this->entityManager->flush();

        return new SignInResult($outcome, $user);
    }
}
