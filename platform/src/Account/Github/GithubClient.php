<?php

namespace App\Account\Github;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Connexion avec GitHub (OAuth 2, application OAuth déclarée chez GitHub) : l'adresse où envoyer l'apprenant, puis
 * l'échange du code reçu au retour contre son profil.
 *
 * Facultative : sans GITHUB_CLIENT_ID ni GITHUB_CLIENT_SECRET, le bouton n'apparaît nulle part.
 */
final readonly class GithubClient
{
    private const string AUTHORIZE_URL = 'https://github.com/login/oauth/authorize';
    private const string TOKEN_URL = 'https://github.com/login/oauth/access_token';
    private const string API_URL = 'https://api.github.com';
    /** Le profil et les adresses email (y compris privées) : rien d'autre, ni dépôts ni organisations. */
    private const string SCOPE = 'read:user user:email';
    /** Adresses de relais de GitHub (« 123+ada@users.noreply.github.com ») : vérifiées, mais personne ne les lit. */
    private const string NOREPLY_DOMAIN = '@users.noreply.github.com';

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire(env: 'GITHUB_CLIENT_ID')]
        private string $clientId = '',
        #[Autowire(env: 'GITHUB_CLIENT_SECRET')]
        private string $clientSecret = '',
    ) {
    }

    public function isEnabled(): bool
    {
        return '' !== trim($this->clientId) && '' !== trim($this->clientSecret);
    }

    /** L'adresse de GitHub où l'apprenant accepte de se connecter ; il revient sur $redirectUri avec un code. */
    public function authorizationUrl(string $state, string $redirectUri): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'scope' => self::SCOPE,
            'state' => $state,
        ]);
    }

    /**
     * Échange le code du retour contre un jeton, puis lit le profil et les adresses vérifiées. Le jeton n'est pas
     * gardé : il ne sert qu'à cette lecture.
     *
     * @throws GithubException
     */
    public function fetchProfile(string $code, string $redirectUri): GithubProfile
    {
        try {
            $token = $this->httpClient->request('POST', self::TOKEN_URL, [
                'headers' => ['Accept' => 'application/json'],
                'body' => ['client_id' => $this->clientId, 'client_secret' => $this->clientSecret, 'code' => $code, 'redirect_uri' => $redirectUri],
            ])->toArray();
            if (!\is_string($token['access_token'] ?? null)) {
                // Code expiré ou déjà utilisé : GitHub répond 200 avec « error ».
                throw new GithubException(sprintf('GitHub a refusé le code : %s.', \is_string($token['error'] ?? null) ? $token['error'] : 'réponse inattendue'));
            }

            $headers = ['Authorization' => 'Bearer '.$token['access_token'], 'Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'];
            $user = $this->httpClient->request('GET', self::API_URL.'/user', ['headers' => $headers])->toArray();
            $emails = $this->httpClient->request('GET', self::API_URL.'/user/emails', ['headers' => $headers])->toArray();
        } catch (ExceptionInterface $e) {
            throw new GithubException('GitHub ne répond pas comme prévu : '.$e->getMessage(), previous: $e);
        }

        if (!\is_int($user['id'] ?? null) || !\is_string($user['login'] ?? null)) {
            throw new GithubException('Profil GitHub incomplet.');
        }

        return new GithubProfile((string) $user['id'], $user['login'], \is_string($user['name'] ?? null) ? $user['name'] : null, self::verifiedEmails($emails));
    }

    /**
     * @param array<mixed> $emails réponse de /user/emails
     *
     * @return list<string>
     */
    private static function verifiedEmails(array $emails): array
    {
        $verified = [];
        foreach ($emails as $email) {
            if (!\is_array($email) || true !== ($email['verified'] ?? null) || !\is_string($email['email'] ?? null)) {
                continue;
            }
            $address = User::normalizeEmail($email['email']);
            if (str_ends_with($address, self::NOREPLY_DOMAIN)) {
                continue;
            }
            if (true === ($email['primary'] ?? null)) {
                array_unshift($verified, $address);
            } else {
                $verified[] = $address;
            }
        }

        return array_values(array_unique($verified));
    }
}
