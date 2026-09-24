<?php

namespace App\Account;

use App\Contact\ContactInbox;
use App\Entity\User;
use App\Instance\Branding;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/** Prévient l'ancienne adresse d'un compte qu'elle vient d'être remplacée. */
final readonly class EmailChangeNotice
{
    public function __construct(
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        private Branding $branding,
        private ContactInbox $contact,
        #[Autowire(env: 'MAILER_FROM')]
        private string $mailerFrom,
    ) {
    }

    public function send(User $user, string $previousEmail): void
    {
        try {
            $this->mailer->send((new TemplatedEmail())
                ->from(Address::create($this->mailerFrom))
                ->to(new Address($previousEmail, (string) $user->getDisplayName()))
                ->subject(sprintf('Votre adresse sur %s a changé', $this->branding->name()))
                ->htmlTemplate('emails/email_changed.html.twig')
                ->textTemplate('emails/email_changed.txt.twig')
                ->context(['user' => $user, 'newEmail' => (string) $user->getEmail(), 'contact' => $this->contact->recipient()]));
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Avis de changement d\'adresse non envoyé : {message}', ['message' => $e->getMessage(), 'user' => $user->getId()]);
        }
    }
}
