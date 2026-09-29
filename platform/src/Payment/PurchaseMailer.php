<?php

namespace App\Payment;

use App\Content\ContentRepository;
use App\Entity\Purchase;
use App\Instance\Branding;
use App\Legal\LegalInfo;
use App\Mail\Sender;
use Psr\Log\LoggerInterface;
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
        private Sender $sender,
        private Branding $branding,
        private LegalInfo $legal,
    ) {
    }

    /** N'échoue jamais : l'accès est ouvert, l'email n'en est que la confirmation. */
    public function sendConfirmation(Purchase $purchase): void
    {
        $track = $this->content->findTrack($purchase->getTrackId());
        $email = $this->sender->email()
            ->to(new Address($purchase->getCustomerEmail(), (string) $purchase->getUser()?->getDisplayName()))
            ->subject(sprintf('Votre accès au parcours « %s » sur %s', $track->title ?? $purchase->getTrackId(), $this->branding->name()))
            ->htmlTemplate('emails/purchase_confirmation.html.twig')
            ->textTemplate('emails/purchase_confirmation.txt.twig')
            // L'identité du vendeur : la confirmation d'une vente à distance est le support durable du contrat.
            ->context(['purchase' => $purchase, 'track' => $track, 'seller' => $this->legal]);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Confirmation d\'achat non envoyée : {message}', ['message' => $e->getMessage(), 'purchase' => $purchase->getId()]);
        }
    }
}
