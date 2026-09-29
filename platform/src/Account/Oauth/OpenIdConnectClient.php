<?php

namespace App\Account\Oauth;

use App\Entity\User;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Un fournisseur OpenID Connect (Google, LinkedIn) : le code du retour s'échange contre un jeton, qui lit le profil
 * normalisé du point « userinfo » (sub, email, email_verified, name). Le jeton n'est pas gardé.
 *
 * On lit « userinfo » plutôt que de vérifier la signature de l'id_token : la réponse vient du fournisseur, en HTTPS,
 * en échange d'un code que seul ce serveur (avec son secret) peut utiliser. C'est ce que prévoit OpenID Connect pour
 * un client confidentiel, et cela évite de gérer ses clés de signature.
 */
abstract readonly class OpenIdConnectClient implements OauthProvider
{
    /** L'identité, l'adresse et le nom : rien d'autre. */
    private const string SCOPE = 'openid email profile';

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $clientId,
        private string $clientSecret,
    ) {
    }

    abstract protected function authorizeUrl(): string;

    abstract protected function tokenUrl(): string;

    abstract protected function userinfoUrl(): string;

    public function isEnabled(): bool
    {
        return '' !== trim($this->clientId) && '' !== trim($this->clientSecret);
    }

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        return $this->authorizeUrl().'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'scope' => self::SCOPE,
            'state' => $state,
        ]);
    }

    public function fetchProfile(string $code, string $redirectUri): ExternalProfile
    {
        try {
            $token = $this->httpClient->request('POST', $this->tokenUrl(), [
                'headers' => ['Accept' => 'application/json'],
                'body' => ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri, 'client_id' => $this->clientId, 'client_secret' => $this->clientSecret],
            ])->toArray(false);
            if (!\is_string($token['access_token'] ?? null)) {
                // Code expiré ou déjà utilisé : réponse 400 avec « error ».
                throw new OauthException(sprintf('%s a refusé le code : %s.', $this->label(), \is_string($token['error'] ?? null) ? $token['error'] : 'réponse inattendue'));
            }
            $info = $this->httpClient->request('GET', $this->userinfoUrl(), [
                'headers' => ['Authorization' => 'Bearer '.$token['access_token'], 'Accept' => 'application/json'],
            ])->toArray();
        } catch (ExceptionInterface $e) {
            throw new OauthException(sprintf('%s ne répond pas comme prévu : %s', $this->label(), $e->getMessage()), previous: $e);
        }

        if (!\is_string($info['sub'] ?? null) || '' === $info['sub']) {
            throw new OauthException(sprintf('Profil %s incomplet.', $this->label()));
        }
        $email = \is_string($info['email'] ?? null) ? User::normalizeEmail($info['email']) : null;
        // Certains fournisseurs écrivent le booléen en texte ; tout le reste compte pour « non vérifiée ».
        $verified = null !== $email && '' !== $email && \in_array($info['email_verified'] ?? null, [true, 'true'], true);
        $name = \is_string($info['name'] ?? null) ? $info['name'] : null;

        return new ExternalProfile($this->name(), $info['sub'], $email ?? $name ?? $info['sub'], $name, $verified ? [$email] : []);
    }
}
