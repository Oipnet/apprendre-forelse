<?php

namespace App\Tests;

use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Le client HTTP des tests (framework.http_client.mock_response_factory) : l'API de Have I Been Pwned simulée, rien
 * d'autre. Un test déclare un mot de passe compromis, ou l'API en panne ; toute autre requête échoue comme sans réseau.
 * L'état est statique, le client de test redémarrant le kernel à chaque requête : appeler reset() dans setUp().
 */
final class PwnedPasswordsMock
{
    /** @var list<string> mots de passe publiés dans une fuite */
    public static array $compromised = [];
    public static bool $down = false;

    public static function reset(): void
    {
        self::$compromised = [];
        self::$down = false;
    }

    /** @param array<string, mixed> $options */
    public function __invoke(string $method, string $url, array $options = []): MockResponse
    {
        if (!preg_match('#^https://api\.pwnedpasswords\.com/range/([0-9A-F]{5})$#i', $url, $m)) {
            throw new TransportException(sprintf('Pas de réseau dans les tests (%s %s).', $method, $url));
        }
        if (self::$down) {
            return new MockResponse('', ['http_code' => 503]);
        }
        // Comme l'API : les suffixes des empreintes qui commencent par ce préfixe, et leur nombre d'apparitions.
        $lines = ['0000000000000000000000000000000000A:1'];
        foreach (self::$compromised as $password) {
            $hash = strtoupper(sha1($password));
            if (str_starts_with($hash, strtoupper($m[1]))) {
                $lines[] = substr($hash, 5).':42';
            }
        }

        return new MockResponse(implode("\r\n", $lines));
    }
}
