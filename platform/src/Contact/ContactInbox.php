<?php

namespace App\Contact;

use App\Entity\ContactMessage;
use App\Legal\LegalInfo;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Reçoit un message de contact : enregistré d'abord (rien ne se perd), puis transmis par email à l'équipe, avec
 * l'adresse de l'expéditeur en Reply-To. Destinataire : CONTACT_EMAIL, à défaut l'adresse de l'éditeur.
 * Aucun accusé de réception n'est envoyé à l'expéditeur : le formulaire ne doit pas servir à écrire à un tiers.
 */
final readonly class ContactInbox
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MailerInterface $mailer,
        private LegalInfo $legal,
        private LoggerInterface $logger,
        #[Autowire(env: 'MAILER_FROM')]
        private string $mailerFrom,
        #[Autowire(env: 'CONTACT_EMAIL')]
        private string $contactEmail,
    ) {
    }

    /** Une adresse reçoit les messages : le formulaire peut s'afficher. */
    public function isOpen(): bool
    {
        return '' !== $this->recipient();
    }

    public function recipient(): string
    {
        return trim($this->contactEmail) ?: trim($this->legal->publisherEmail);
    }

    public function receive(ContactMessage $message): void
    {
        $this->entityManager->persist($message);
        $this->entityManager->flush();

        $email = (new TemplatedEmail())
            ->from(Address::create($this->mailerFrom))
            ->to($this->recipient())
            ->replyTo(new Address((string) $message->getEmail(), (string) $message->getName()))
            ->subject(sprintf('[Contact] %s : %s', $message->getSubject()?->label(), $message->getOrganization() ?? $message->getName()))
            ->textTemplate('emails/contact_message.txt.twig')
            ->context(['contact' => $message]);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            // Le message est en base, visible dans l'admin : on note l'échec sans le montrer à l'expéditeur.
            $this->logger->error('Message de contact {id} non transmis par email : {message}', ['id' => $message->getId(), 'message' => $e->getMessage()]);
        }
    }
}
