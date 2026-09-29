<?php

namespace App\Account;

use App\Entity\User;
use App\Instance\Branding;
use App\Mail\Sender;
use App\Repository\UserRepository;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;

/** Envoie le lien de nouveau mot de passe, si l'adresse demandée a un compte (voir PasswordResetRequested). */
#[AsMessageHandler]
final readonly class SendPasswordResetLink
{
    public function __construct(
        private UserRepository $users,
        private ResetPasswordHelperInterface $resetPasswordHelper,
        private MailerInterface $mailer,
        private Branding $branding,
        private Sender $sender,
    ) {
    }

    public function __invoke(PasswordResetRequested $request): void
    {
        $user = $this->users->findOneByEmail($request->email);
        if (!$user instanceof User) {
            return;
        }

        try {
            $resetToken = $this->resetPasswordHelper->generateResetToken($user);
        } catch (ResetPasswordExceptionInterface) {
            // Demande trop rapprochée de la précédente : le premier lien est encore valable.
            return;
        }

        // L'email ne contient pas de mot de passe : un lien pour en choisir un.
        $this->mailer->send($this->sender->email()
            ->to(new Address((string) $user->getEmail(), (string) $user->getDisplayName()))
            ->subject(sprintf('Choisissez un nouveau mot de passe sur %s', $this->branding->name()))
            ->htmlTemplate('emails/reset_password.html.twig')
            ->textTemplate('emails/reset_password.txt.twig')
            ->context(['resetToken' => $resetToken, 'user' => $user]));
    }
}
