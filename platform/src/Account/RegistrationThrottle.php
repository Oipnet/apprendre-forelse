<?php

namespace App\Account;

use App\Security\ClientKey;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Les limites de l'inscription, par adresse IP (config/packages/rate_limiter.yaml). Un email déjà pris se voit
 * forcément : un nouveau compte, lui, est connecté aussitôt. Ce qu'on peut faire, c'est ralentir qui teste une liste
 * d'adresses : passé un certain nombre de refus, plus aucune inscription n'aboutit depuis cette adresse IP, que l'email
 * soit pris ou non. Chaque adresse ne compte qu'une fois par adresse IP : qui teste une liste essaie des adresses
 * différentes, alors qu'une classe derrière la même box retente les mêmes (24/11/2026 : dix essais avec des adresses
 * déjà inscrites ont bloqué une salle entière pendant une heure). De même pour les codes d'invitation : un code ouvre
 * des comptes, parfois des parcours payants.
 */
final readonly class RegistrationThrottle
{
    public function __construct(
        #[Autowire(service: 'limiter.registration_duplicate')]
        private RateLimiterFactoryInterface $duplicates,
        #[Autowire(service: 'limiter.registration_duplicate_email')]
        private RateLimiterFactoryInterface $duplicateEmails,
        #[Autowire(service: 'limiter.invitation_code')]
        private RateLimiterFactoryInterface $invitationCodes,
    ) {
    }

    /** Trop d'inscriptions refusées pour email déjà pris : plus aucune n'aboutit. */
    public function isBlocked(?string $ip): bool
    {
        return 0 === $this->duplicates->create(ClientKey::of($ip))->consume(0)->getRemainingTokens();
    }

    /** Trop de codes d'invitation inconnus essayés. */
    public function tooManyCodes(?string $ip): bool
    {
        return 0 === $this->invitationCodes->create(ClientKey::of($ip))->consume(0)->getRemainingTokens();
    }

    public function recordUnknownCode(?string $ip): void
    {
        $this->invitationCodes->create(ClientKey::of($ip))->consume();
    }

    /** Un refus pour email déjà pris (adresse normalisée, voir User::setEmail), compté une fois par adresse IP. */
    public function recordDuplicate(?string $ip, string $email): void
    {
        $key = ClientKey::of($ip);
        if ($this->duplicateEmails->create($key.' '.$email)->consume()->isAccepted()) {
            $this->duplicates->create($key)->consume();
        }
    }
}
