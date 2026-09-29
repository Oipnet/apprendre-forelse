<?php

namespace App\Account\Oauth;

use App\Entity\ExternalIdentity;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Connexion avec LinkedIn (« Sign In with LinkedIn using OpenID Connect », produit à ajouter à l'application sur
 * LinkedIn Developers).
 *
 * Facultative : sans LINKEDIN_CLIENT_ID ni LINKEDIN_CLIENT_SECRET, le bouton n'apparaît nulle part.
 */
final readonly class LinkedinClient extends OpenIdConnectClient
{
    public function __construct(
        HttpClientInterface $httpClient,
        #[Autowire(env: 'LINKEDIN_CLIENT_ID')]
        string $clientId = '',
        #[Autowire(env: 'LINKEDIN_CLIENT_SECRET')]
        string $clientSecret = '',
    ) {
        parent::__construct($httpClient, $clientId, $clientSecret);
    }

    public function name(): string
    {
        return ExternalIdentity::LINKEDIN;
    }

    public function label(): string
    {
        return 'LinkedIn';
    }

    protected function authorizeUrl(): string
    {
        return 'https://www.linkedin.com/oauth/v2/authorization';
    }

    protected function tokenUrl(): string
    {
        return 'https://www.linkedin.com/oauth/v2/accessToken';
    }

    protected function userinfoUrl(): string
    {
        return 'https://api.linkedin.com/v2/userinfo';
    }
}
