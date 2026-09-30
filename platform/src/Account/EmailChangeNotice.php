<?php

namespace App\Account;

use App\Entity\User;
use App\Theme\Theme;
use App\Mail\Sender;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/** Prévient l'ancienne adresse d'un compte qu'elle vient d'être remplacée. */
final readonly class EmailChangeNotice
{
    public function __construct(
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        private Theme $theme,
        private Sender $sender,
    ) {
    }

    public function send(User $user, string $previousEmail): void
    {
        try {
            $this->mailer->send($this->sender->email()
                ->to(new Address($previousEmail, (string) $user->getDisplayName()))
                ->subject(sprintf('Votre adresse sur %s a changé', $this->theme->name()))
                ->htmlTemplate('emails/email_changed.html.twig')
                ->textTemplate('emails/email_changed.txt.twig')
                ->context(['user' => $user, 'newEmail' => (string) $user->getEmail(), 'contact' => $this->sender->contact()]));
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Avis de changement d\'adresse non envoyé : {message}', ['message' => $e->getMessage(), 'user' => $user->getId()]);
        }
    }
}
