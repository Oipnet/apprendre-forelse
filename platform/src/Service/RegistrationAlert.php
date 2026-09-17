<?php

namespace App\Service;

use App\Controller\Admin\DashboardController;
use App\Controller\Admin\UserCrudController;
use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Prévient l'équipe par email à chaque nouvelle inscription (qui, quelle cohorte, lien vers sa fiche
 * dans l'admin). Destinataire : REGISTRATION_ALERT_EMAIL ; vide = pas d'alerte.
 */
final class RegistrationAlert
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly AdminUrlGenerator $adminUrls,
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

        // Hors requête EasyAdmin, le tableau de bord doit être nommé : il y en a deux (/admin et /cohorte).
        $adminUrl = $this->adminUrls
            ->unsetAll()
            ->setDashboard(DashboardController::class)
            ->setController(UserCrudController::class)
            ->setAction(Action::DETAIL)
            ->setEntityId($user->getId())
            ->generateUrl();

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
