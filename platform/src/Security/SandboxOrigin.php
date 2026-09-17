<?php

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * Origine « bac à sable » : elle sert uniquement le relais d'aperçu (/sandbox) et son
 * Service Worker, pour que le HTML/JS produit par l'apprenant n'ait aucun accès à la plateforme.
 */
final readonly class SandboxOrigin
{
    public const string RELAY_PATH = '/sandbox';

    public string $origin;
    public string $platformOrigin;

    public function __construct(
        #[Autowire(env: 'SANDBOX_ORIGIN')] string $sandboxOrigin,
        #[Autowire(env: 'DEFAULT_URI')] string $platformUri,
    ) {
        $this->origin = self::originOf($sandboxOrigin);
        $this->platformOrigin = self::originOf($platformUri);
        if ($this->origin === $this->platformOrigin) {
            throw new \LogicException('SANDBOX_ORIGIN doit différer de l\'origine de la plateforme (DEFAULT_URI).');
        }
    }

    public function isSandboxRequest(Request $request): bool
    {
        return $request->getSchemeAndHttpHost() === $this->origin;
    }

    public function relayUrl(): string
    {
        return $this->origin.self::RELAY_PATH;
    }

    private static function originOf(string $url): string
    {
        $parts = parse_url($url);
        if (!isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException(sprintf('URL invalide : « %s ».', $url));
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
