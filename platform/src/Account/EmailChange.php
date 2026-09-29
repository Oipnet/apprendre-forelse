<?php

namespace App\Account;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Le changement d'adresse d'un compte : demandé depuis le profil (mot de passe exigé, adresse libre), confirmé par un
 * lien signé envoyé à la nouvelle adresse. L'adresse actuelle reste valable jusque-là ; l'ancienne est prévenue après.
 */
final readonly class EmailChange
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $users,
        private UserPasswordHasherInterface $hasher,
        private EmailVerifier $verifier,
        private EmailChangeNotice $notice,
        private ClockInterface $clock,
        #[Autowire(service: 'limiter.email_confirmation')]
        private RateLimiterFactoryInterface $confirmationLimiter,
    ) {
    }

    /**
     * Enregistre le profil ; si l'adresse change, envoie le lien qui la confirmera.
     *
     * @param bool $recentlySignedIn pour un compte sans mot de passe : il vient de repasser par un fournisseur lié (RecentSignIn)
     */
    public function request(User $user, string $displayName, string $email, ?string $password, bool $recentlySignedIn = false): EmailChangeOutcome
    {
        // Même forme que l'adresse enregistrée : une adresse qui ne diffère que par la casse est la même.
        $email = User::normalizeEmail($email);
        $emailChanged = $email !== $user->getEmail();
        $identityConfirmed = $user->hasPassword() ? $this->hasher->isPasswordValid($user, (string) $password) : $recentlySignedIn;
        if ($emailChanged && !$identityConfirmed) {
            // Avant de dire si l'adresse est prise : sans le mot de passe, on n'apprend rien.
            return EmailChangeOutcome::PasswordRequired;
        }
        if ($emailChanged && null !== $this->users->findOneByEmail($email)) {
            return EmailChangeOutcome::EmailTaken;
        }

        $user->setDisplayName(trim($displayName));
        $this->entityManager->flush();

        return $emailChanged ? $this->send($user, $email) : EmailChangeOutcome::Saved;
    }

    /** Un nouveau lien pour l'adresse du compte, tant qu'elle n'est pas confirmée. */
    public function resend(User $user): EmailChangeOutcome
    {
        return $user->isEmailVerified() ? EmailChangeOutcome::AlreadyConfirmed : $this->send($user, null);
    }

    /** Le lien reçu par email (voir EmailVerifier) ; il fonctionne sans être connecté. */
    public function confirm(Request $request): EmailConfirmationOutcome
    {
        $user = $this->users->find($request->query->getInt('id'));
        // Les liens envoyés avant que les adresses soient mises en minuscules gardent la casse saisie.
        $email = User::normalizeEmail($request->query->getString('email'));
        if (null === $user || !$this->verifier->isSigned($request)) {
            return EmailConfirmationOutcome::Invalid;
        }
        if ($user->isEmailVerified() && $user->getEmail() === $email) {
            return EmailConfirmationOutcome::AlreadyConfirmed;
        }
        if (!$this->verifier->matches($user, $request->query->getString('check'))) {
            return EmailConfirmationOutcome::Outdated;
        }
        $other = $this->users->findOneByEmail($email);
        if (null !== $other && $other->getId() !== $user->getId()) {
            return EmailConfirmationOutcome::EmailTaken;
        }

        $previous = (string) $user->getEmail();
        $user->confirmEmail($email, $this->clock->now());
        $this->entityManager->flush();
        if ($previous === $email) {
            return EmailConfirmationOutcome::Confirmed;
        }
        // L'ancienne adresse apprend le changement : si ce n'était pas son titulaire, il sait qu'il doit réagir.
        $this->notice->send($user, $previous);

        return EmailConfirmationOutcome::Changed;
    }

    /** Un lien par email coûte un envoi : quelques-uns par heure et par compte. */
    private function send(User $user, ?string $newEmail): EmailChangeOutcome
    {
        if (!$this->confirmationLimiter->create('user-'.$user->getId())->consume()->isAccepted()) {
            return EmailChangeOutcome::Throttled;
        }

        return $this->verifier->send($user, $newEmail) ? EmailChangeOutcome::Sent : EmailChangeOutcome::SendFailed;
    }
}
