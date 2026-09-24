<?php

namespace App\Account;

use App\Entity\User;
use App\Instance\Branding;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Prévient le titulaire d'un compte qu'une inscription a été tentée avec son adresse.
 *
 * La page d'inscription ne peut pas cacher qu'un email est pris (un nouveau compte est connecté aussitôt) :
 * elle reste sobre, et c'est le titulaire qui apprend la tentative — oubli de son compte, ou quelqu'un qui
 * teste des adresses. Un email par jour et par compte au plus.
 */
final readonly class RegistrationAttemptNotice
{
    public function __construct(
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        private Branding $branding,
        #[Autowire(service: 'limiter.registration_notice')]
        private RateLimiterFactoryInterface $limiter,
        #[Autowire(env: 'MAILER_FROM')]
        private string $mailerFrom,
    ) {
    }

    public function send(User $user): void
    {
        if (!$this->limiter->create((string) $user->getId())->consume()->isAccepted()) {
            return;
        }

        try {
            $this->mailer->send((new TemplatedEmail())
                ->from(Address::create($this->mailerFrom))
                ->to(new Address((string) $user->getEmail(), (string) $user->getDisplayName()))
                ->subject(sprintf('Vous avez déjà un compte %s', $this->branding->name()))
                ->htmlTemplate('emails/registration_attempt.html.twig')
                ->textTemplate('emails/registration_attempt.txt.twig')
                ->context(['user' => $user]));
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Email de tentative d\'inscription non envoyé : {message}', ['message' => $e->getMessage(), 'user' => $user->getId()]);
        }
    }
}
