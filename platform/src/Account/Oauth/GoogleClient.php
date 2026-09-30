<?php

namespace App\Account\Oauth;

use App\Entity\ExternalIdentity;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Connexion avec Google (OpenID Connect, client OAuth « application Web » de la Google Cloud Console).
 *
 * Facultative : sans GOOGLE_CLIENT_ID ni GOOGLE_CLIENT_SECRET, le bouton n'apparaît nulle part.
 */
final readonly class GoogleClient extends OpenIdConnectClient
{
    public function __construct(
        HttpClientInterface $httpClient,
        #[Autowire(env: 'GOOGLE_CLIENT_ID')]
        string $clientId = '',
        #[Autowire(env: 'GOOGLE_CLIENT_SECRET')]
        string $clientSecret = '',
    ) {
        parent::__construct($httpClient, $clientId, $clientSecret);
    }

    public function name(): string
    {
        return ExternalIdentity::GOOGLE;
    }

    public function label(): string
    {
        return 'Google';
    }

    protected function authorizeUrl(): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth';
    }

    protected function tokenUrl(): string
    {
        return 'https://oauth2.googleapis.com/token';
    }

    protected function userinfoUrl(): string
    {
        return 'https://openidconnect.googleapis.com/v1/userinfo';
    }
}
