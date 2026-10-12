<?php

namespace App\Certificate;

use App\Entity\Certificate;
use App\Entity\User;
use App\Mail\Sender;
use App\Repository\CertificateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Révoquer un certificat, ou le rétablir après une contestation. Chaque décision est gardée dans son historique, et
 * l'apprenant est prévenu par email : il peut contester depuis la page du certificat (formulaire de contact).
 *
 * Deux origines : l'administration (à la main), et la vérification côté serveur des réussites, qui appelle
 * revokeForExercise() quand elle invalide un exercice.
 */
final readonly class CertificateRevocation
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CertificateRepository $certificates,
        private MailerInterface $mailer,
        private Sender $sender,
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    public function revoke(Certificate $certificate, string $reason): void
    {
        if ($certificate->isRevoked()) {
            return;
        }
        $certificate->revoke($reason, $this->clock->now());
        $this->entityManager->flush();
        $this->notify($certificate, 'revoked');
    }

    public function reinstate(Certificate $certificate, ?string $note = null): void
    {
        if (!$certificate->isRevoked()) {
            return;
        }
        $certificate->reinstate($note, $this->clock->now());
        $this->entityManager->flush();
        $this->notify($certificate, 'reinstated');
    }

    /**
     * Point d'entrée de la vérification des réussites : un exercice n'est pas confirmé, le certificat en vigueur du
     * parcours qui le compte est révoqué.
     *
     * @return Certificate|null le certificat révoqué, s'il y en avait un
     */
    public function revokeForExercise(User $user, string $trackId, string $exerciseId, string $reason): ?Certificate
    {
        $certificate = $this->certificates->findValid($user, $trackId);
        if (null === $certificate || !$certificate->hasExercise($exerciseId)) {
            return null;
        }
        $this->revoke($certificate, $reason);

        return $certificate;
    }

    /** N'échoue jamais : la décision est enregistrée, l'email n'en est que l'avis. */
    private function notify(Certificate $certificate, string $template): void
    {
        $user = $certificate->getUser();
        $email = $this->sender->email()
            ->to(new Address((string) $user->getEmail(), (string) $user->getDisplayName()))
            ->subject(match ($template) {
                'revoked' => sprintf('Votre certificat « %s » a été révoqué', $certificate->getTrackTitle()),
                default => sprintf('Votre certificat « %s » est rétabli', $certificate->getTrackTitle()),
            })
            ->htmlTemplate(sprintf('emails/certificate_%s.html.twig', $template))
            ->textTemplate(sprintf('emails/certificate_%s.txt.twig', $template))
            ->context(['certificate' => $certificate, 'user' => $user]);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Avis de certificat {code} non envoyé : {message}', ['code' => $certificate->getCode(), 'message' => $e->getMessage()]);
        }
    }
}
