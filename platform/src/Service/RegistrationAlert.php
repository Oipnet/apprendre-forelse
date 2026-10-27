<?php

namespace App\Service;

use App\Entity\Cohort;
use App\Entity\User;
use App\Theme\Theme;
use App\Mail\Sender;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Prévient l'équipe par email à chaque nouvelle inscription (qui, quelle cohorte, lien vers sa fiche
 * dans l'admin), et à chaque classe gratuite créée par un formateur : un prospect à relancer.
 * Destinataire : REGISTRATION_ALERT_EMAIL ; vide = pas d'alerte.
 */
final class RegistrationAlert
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $router,
        private readonly LoggerInterface $logger,
        private readonly Sender $sender,
        private readonly Theme $theme,
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

        // Plusieurs instances peuvent écrire à la même personne : l'objet dit laquelle.
        $this->send($recipient, sprintf('[%s] Nouvelle inscription : %s', $this->theme->name(), $user->getDisplayName()), 'emails/registration_alert.txt.twig', ['user' => $user, 'adminUrl' => $adminUrl], $user);
    }

    /** Un formateur vient de créer une classe gratuite (FreeClasses). N'échoue jamais, comme notify(). */
    public function notifyFreeClass(Cohort $cohort, User $chef): void
    {
        $recipient = trim($this->recipient);
        if ('' === $recipient) {
            return;
        }

        $cohortUrl = $this->router->generate('chef_cohort', ['id' => $cohort->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
        $this->send($recipient, sprintf('[%s] Classe gratuite créée : %s', $this->theme->name(), $cohort->getName()), 'emails/free_class_alert.txt.twig', ['cohort' => $cohort, 'chef' => $chef, 'cohortUrl' => $cohortUrl], $chef);
    }

    /** @param array<string, mixed> $context */
    private function send(string $recipient, string $subject, string $template, array $context, User $user): void
    {
        $email = (new TemplatedEmail())
            ->from($this->sender->from())
            ->to($recipient)
            ->subject($subject)
            ->textTemplate($template)
            ->context($context);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            // Brevo indisponible, clé invalide… : le compte (ou la classe) existe, on note l'échec et on continue.
            $this->logger->error('Alerte « {subject} » non envoyée : {message}', ['subject' => $subject, 'message' => $e->getMessage(), 'user' => $user->getEmail()]);
        }
    }
}
