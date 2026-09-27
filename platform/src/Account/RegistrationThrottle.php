<?php

namespace App\Account;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Les limites de l'inscription, par adresse IP (config/packages/rate_limiter.yaml). Un email déjà pris se voit
 * forcément : un nouveau compte, lui, est connecté aussitôt. Ce qu'on peut faire, c'est ralentir qui teste une liste
 * d'adresses : passé un certain nombre de refus, plus aucune inscription n'aboutit depuis cette adresse IP, que l'email
 * soit pris ou non. De même pour les codes d'invitation : un code ouvre des comptes, parfois des parcours payants.
 */
final readonly class RegistrationThrottle
{
    public function __construct(
        #[Autowire(service: 'limiter.registration_duplicate')]
        private RateLimiterFactoryInterface $duplicates,
        #[Autowire(service: 'limiter.invitation_code')]
        private RateLimiterFactoryInterface $invitationCodes,
    ) {
    }

    /** Trop d'inscriptions refusées pour email déjà pris : plus aucune n'aboutit. */
    public function isBlocked(?string $ip): bool
    {
        return 0 === $this->duplicates->create($ip ?? 'inconnu')->consume(0)->getRemainingTokens();
    }

    /** Trop de codes d'invitation inconnus essayés. */
    public function tooManyCodes(?string $ip): bool
    {
        return 0 === $this->invitationCodes->create($ip ?? 'inconnu')->consume(0)->getRemainingTokens();
    }

    public function recordUnknownCode(?string $ip): void
    {
        $this->invitationCodes->create($ip ?? 'inconnu')->consume();
    }

    public function recordDuplicate(?string $ip): void
    {
        $this->duplicates->create($ip ?? 'inconnu')->consume();
    }
}
