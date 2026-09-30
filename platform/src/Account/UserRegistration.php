<?php

namespace App\Account;

use App\Cohort\CohortManagement;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\RegistrationAlert;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Crée le compte d'un nouvel apprenant : mot de passe, cohorte et ses accès, alerte de l'équipe, email de confirmation. */
final readonly class UserRegistration
{
    public function __construct(
        private UserPasswordHasherInterface $hasher,
        private CohortManagement $cohorts,
        private RegistrationAlert $alert,
        private EmailVerifier $verifier,
        private UserRepository $users,
        private RegistrationAttemptNotice $notice,
    ) {
    }

    /** @param string|null $code code d'invitation saisi : la cohorte active qu'il désigne, avec ses accès */
    public function register(User $user, string $plainPassword, ?string $code): RegistrationResult
    {
        $user->setPassword($this->hasher->hashPassword($user, $plainPassword));
        $this->cohorts->enroll($user, $code);
        $this->alert->notify($user);

        return new RegistrationResult($this->verifier->send($user));
    }

    /** Compte venu d'un fournisseur (GitHub, Google, LinkedIn) : pas de mot de passe, adresse déjà confirmée par lui, sans email à envoyer. */
    public function registerWithoutPassword(User $user, ?string $code): void
    {
        $this->cohorts->enroll($user, $code);
        $this->alert->notify($user);
    }

    /** Inscription refusée, l'email étant pris : son titulaire l'apprend (oubli de son compte, ou adresses testées). */
    public function notifyHolder(User $attempt): void
    {
        $holder = $this->users->findOneByEmail((string) $attempt->getEmail());
        if ($holder instanceof User) {
            $this->notice->send($holder);
        }
    }
}
