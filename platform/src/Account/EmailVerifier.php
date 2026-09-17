<?php

namespace App\Account;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Confirmation d'une adresse email par un lien signé : à l'inscription, et avant de remplacer l'adresse d'un compte.
 *
 * Le lien porte l'adresse à confirmer, signée avec APP_SECRET et valable 48 heures. Une empreinte de l'état du
 * compte (adresse actuelle, date de la dernière confirmation) le rend caduc dès qu'il a servi ou que l'adresse a
 * changé entre-temps : aucune table de jetons à tenir.
 */
final readonly class EmailVerifier
{
    public const int VALIDITY_HOURS = 48;

    public function __construct(
        private UriSigner $signer,
        private UrlGeneratorInterface $urls,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        #[Autowire(env: 'MAILER_FROM')]
        private string $mailerFrom,
    ) {
    }

    /**
     * Envoie le lien à l'adresse à confirmer : celle du compte, ou la nouvelle adresse demandée.
     *
     * @return bool false si l'email n'a pas pu partir
     */
    public function send(User $user, ?string $newEmail = null): bool
    {
        $email = $newEmail ?? (string) $user->getEmail();
        $link = $this->signer->sign(
            $this->urls->generate('app_account_confirm_email', ['id' => $user->getId(), 'email' => $email, 'check' => self::fingerprint($user)], UrlGeneratorInterface::ABSOLUTE_URL),
            new \DateInterval(sprintf('PT%dH', self::VALIDITY_HOURS)),
        );

        $message = (new TemplatedEmail())
            ->from(Address::create($this->mailerFrom))
            ->to($email)
            ->subject(null === $newEmail ? 'Confirmez votre adresse email' : 'Confirmez votre nouvelle adresse email')
            ->htmlTemplate('emails/confirm_email.html.twig')
            ->textTemplate('emails/confirm_email.txt.twig')
            ->context(['user' => $user, 'link' => $link, 'change' => null !== $newEmail, 'hours' => self::VALIDITY_HOURS]);

        try {
            $this->mailer->send($message);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Email de confirmation d\'adresse non envoyé : {message}', ['message' => $e->getMessage(), 'user' => $user->getId()]);

            return false;
        }

        return true;
    }

    /** Le lien de la requête est intact et n'a pas expiré (l'empreinte du compte se vérifie à part). */
    public function isSigned(Request $request): bool
    {
        return $this->signer->checkRequest($request);
    }

    /** Le lien a été émis pour le compte dans son état actuel. */
    public function matches(User $user, string $fingerprint): bool
    {
        return hash_equals(self::fingerprint($user), $fingerprint);
    }

    private static function fingerprint(User $user): string
    {
        return substr(hash('sha256', $user->getEmail().'|'.$user->getEmailVerifiedAt()?->format('U.u')), 0, 20);
    }
}
