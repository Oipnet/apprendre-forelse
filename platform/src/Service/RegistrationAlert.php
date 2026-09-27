<?php

namespace App\Service;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Prévient l'équipe par email à chaque nouvelle inscription (qui, quelle cohorte, lien vers sa fiche
 * dans l'admin). Destinataire : REGISTRATION_ALERT_EMAIL ; vide = pas d'alerte.
 */
final class RegistrationAlert
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $router,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'MAILER_FROM')]
        private readonly string $mailerFrom,
        #[Autowire(env: 'REGISTRATION_ALERT_EMAIL')]
        private readonly string $recipient,
    ) {
    }

    /** À appeler une fois l'apprenant enregistré (il a un id). N'échoue jamais : l'inscription prime sur l'alerte. */
    public function notify(User $user): void
    {
        $recipient = trim($this->recipient);
        if ('' === $recipient) {
            return;
        }

        // La route de la fiche dans le tableau de bord /admin (UserCrudController) : il y en a deux, /admin et /cohorte.
        $adminUrl = $this->router->generate('admin_users_detail', ['entityId' => $user->getId()], UrlGeneratorInterface::ABSOLUTE_URL);

        $email = (new TemplatedEmail())
            ->from(Address::create($this->mailerFrom))
            ->to($recipient)
            ->subject(sprintf('Nouvelle inscription : %s', $user->getDisplayName()))
            ->textTemplate('emails/registration_alert.txt.twig')
            ->context(['user' => $user, 'adminUrl' => $adminUrl]);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            // Brevo indisponible, clé invalide… : l'apprenant a son compte, on note l'échec et on continue.
            $this->logger->error('Alerte de nouvelle inscription non envoyée : {message}', ['message' => $e->getMessage(), 'user' => $user->getEmail()]);
        }
    }
}
