<?php

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RateLimiter\AbstractRequestRateLimiter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * Le limiteur de la connexion (login_throttling du pare-feu main) : celui de Symfony (DefaultLoginRateLimiter), mais
 * l'adresse IP passe par ClientKey, pour qu'une machine IPv6 ne remette pas ses compteurs à zéro en changeant
 * d'adresse dans son /64. Deux compteurs : par adresse IP, tous comptes confondus, et par compte et adresse IP.
 */
final class LoginRateLimiter extends AbstractRequestRateLimiter
{
    public function __construct(
        #[Autowire(service: 'limiter.login_ip')]
        private readonly RateLimiterFactoryInterface $globalFactory,
        #[Autowire(service: 'limiter.login_account')]
        private readonly RateLimiterFactoryInterface $localFactory,
        #[Autowire('%kernel.secret%')]
        #[\SensitiveParameter]
        private readonly string $secret,
    ) {
    }

    protected function getLimiters(Request $request): array
    {
        $username = (string) $request->attributes->get(SecurityRequestAttributes::LAST_USERNAME, '');
        $username = 1 === preg_match('//u', $username) ? mb_strtolower($username, 'UTF-8') : strtolower($username);
        $ip = ClientKey::of($request->getClientIp());

        return [
            $this->globalFactory->create($ip),
            $this->localFactory->create(hash('xxh128', $username.'-'.$ip.'-'.$this->secret)),
        ];
    }
}
