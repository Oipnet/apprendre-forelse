<?php

namespace App\Payment;

use App\Content\ContentRepository;
use App\Entity\Purchase;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/** Email de confirmation d'achat, avec le lien vers le reçu Stripe (ou vers le compte, où il apparaîtra). */
final readonly class PurchaseMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private ContentRepository $content,
        private LoggerInterface $logger,
        #[Autowire(env: 'MAILER_FROM')]
        private string $mailerFrom,
    ) {
    }

    /** N'échoue jamais : l'accès est ouvert, l'email n'en est que la confirmation. */
    public function sendConfirmation(Purchase $purchase): void
    {
        $track = $this->content->findTrack($purchase->getTrackId());
        $email = (new TemplatedEmail())
            ->from(Address::create($this->mailerFrom))
            ->to($purchase->getCustomerEmail())
            ->subject(sprintf('Votre accès au parcours « %s »', $track?->title ?? $purchase->getTrackId()))
            ->htmlTemplate('emails/purchase_confirmation.html.twig')
            ->textTemplate('emails/purchase_confirmation.txt.twig')
            ->context(['purchase' => $purchase, 'track' => $track]);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Confirmation d\'achat non envoyée : {message}', ['message' => $e->getMessage(), 'purchase' => $purchase->getId()]);
        }
    }
}
