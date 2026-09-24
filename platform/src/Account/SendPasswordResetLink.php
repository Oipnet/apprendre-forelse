<?php

namespace App\Account;

use App\Entity\User;
use App\Instance\Branding;
use App\Repository\UserRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

/** Envoie le lien de nouveau mot de passe, si l'adresse demandée a un compte (voir PasswordResetRequested). */
#[AsMessageHandler]
final readonly class SendPasswordResetLink
{
    public function __construct(
        private UserRepository $users,
        private ResetPasswordHelperInterface $resetPasswordHelper,
        private MailerInterface $mailer,
        private Branding $branding,
        /** Expéditeur des emails (« Nom <adresse> »), voir MAILER_FROM dans .env. */
        #[Autowire(env: 'MAILER_FROM')]
        private string $mailerFrom,
    ) {
    }

    public function __invoke(PasswordResetRequested $request): void
    {
        $user = $this->users->findOneBy(['email' => $request->email]);
        if (!$user instanceof User) {
            return;
        }

        try {
            $resetToken = $this->resetPasswordHelper->generateResetToken($user);
        } catch (ResetPasswordExceptionInterface) {
            // Demande trop rapprochée de la précédente : le premier lien est encore valable.
            return;
        }

        $this->mailer->send((new TemplatedEmail())
            ->from(Address::create($this->mailerFrom))
            ->to(new Address((string) $user->getEmail(), (string) $user->getDisplayName()))
            ->subject(sprintf('Votre nouveau mot de passe %s', $this->branding->name()))
            ->htmlTemplate('emails/reset_password.html.twig')
            ->textTemplate('emails/reset_password.txt.twig')
            ->context(['resetToken' => $resetToken, 'user' => $user]));
    }
}
