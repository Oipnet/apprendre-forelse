<?php

namespace App\Tests\Account;

use App\Account\Oauth\GoogleClient;
use App\Account\Oauth\LinkedinClient;
use App\Account\Oauth\OauthException;
use App\Account\Oauth\OpenIdConnectClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Google et LinkedIn (OpenID Connect) : l'aller vers le fournisseur, l'échange du code, la lecture du profil. */
final class OpenIdConnectClientTest extends TestCase
{
    /** @var list<array{method: string, url: string, body: string}> */
    private array $requests = [];

    /** @return iterable<string, array{class-string<OpenIdConnectClient>, string, string, string}> */
    public static function fournisseurs(): iterable
    {
        yield 'Google' => [GoogleClient::class, 'https://accounts.google.com/o/oauth2/v2/auth', 'https://oauth2.googleapis.com/token', 'https://openidconnect.googleapis.com/v1/userinfo'];
        yield 'LinkedIn' => [LinkedinClient::class, 'https://www.linkedin.com/oauth/v2/authorization', 'https://www.linkedin.com/oauth/v2/accessToken', 'https://api.linkedin.com/v2/userinfo'];
    }

    /**
     * @param class-string<OpenIdConnectClient> $class
     * @param list<MockResponse>                $responses
     */
    private function client(string $class, array $responses, string $id = 'id', string $secret = 'secret'): OpenIdConnectClient
    {
        $queue = $responses;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$queue): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'body' => (string) ($options['body'] ?? '')];

            return array_shift($queue) ?? throw new \LogicException('Appel inattendu : '.$url);
        });

        return new $class($http, $id, $secret);
    }

    #[DataProvider('fournisseurs')]
    public function testLAllerDemandeLIdentiteLAdresseEtLeNomSeulement(string $class, string $authorize, string $token, string $userinfo): void
    {
        $url = $this->client($class, [])->authorizationUrl('etat', 'https://forelse.test/connexion/x/retour');

        $this->assertStringStartsWith($authorize.'?', $url);
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $params);
        $this->assertSame(['response_type' => 'code', 'client_id' => 'id', 'redirect_uri' => 'https://forelse.test/connexion/x/retour', 'scope' => 'openid email profile', 'state' => 'etat'], $params);
    }

    #[DataProvider('fournisseurs')]
    public function testLeCodeSEchangeContreLeProfilEtUneAdresseVerifiee(string $class, string $authorize, string $token, string $userinfo): void
    {
        $profile = $this->client($class, [
            new JsonMockResponse(['access_token' => 'jeton', 'token_type' => 'Bearer', 'id_token' => 'ignore']),
            new JsonMockResponse(['sub' => '1098', 'email' => 'Ada@Example.test', 'email_verified' => true, 'name' => 'Ada Lovelace']),
        ])->fetchProfile('le-code', 'https://forelse.test/retour');

        $this->assertSame('1098', $profile->id);
        $this->assertSame(['ada@example.test'], $profile->verifiedEmails, 'Adresse en minuscules.');
        $this->assertSame('Ada Lovelace', $profile->suggestedDisplayName());
        $this->assertSame(['POST', $token], [$this->requests[0]['method'], $this->requests[0]['url']]);
        parse_str($this->requests[0]['body'], $body);
        $this->assertSame(['grant_type' => 'authorization_code', 'code' => 'le-code', 'redirect_uri' => 'https://forelse.test/retour', 'client_id' => 'id', 'client_secret' => 'secret'], $body);
        $this->assertSame(['GET', $userinfo], [$this->requests[1]['method'], $this->requests[1]['url']]);
    }

    #[DataProvider('fournisseurs')]
    public function testUneAdresseNonVerifieeNEstPasRetenue(string $class, string $authorize, string $token, string $userinfo): void
    {
        $profile = $this->client($class, [
            new JsonMockResponse(['access_token' => 'jeton']),
            new JsonMockResponse(['sub' => '1098', 'email' => 'ada@example.test', 'email_verified' => false, 'name' => 'Ada']),
        ])->fetchProfile('code', 'https://forelse.test/retour');

        $this->assertSame([], $profile->verifiedEmails);
        $this->assertNull($profile->primaryEmail());
    }

    #[DataProvider('fournisseurs')]
    public function testUnBooleenEcritEnTexteCompte(string $class, string $authorize, string $token, string $userinfo): void
    {
        $profile = $this->client($class, [
            new JsonMockResponse(['access_token' => 'jeton']),
            new JsonMockResponse(['sub' => '1098', 'email' => 'ada@example.test', 'email_verified' => 'true']),
        ])->fetchProfile('code', 'https://forelse.test/retour');

        $this->assertSame(['ada@example.test'], $profile->verifiedEmails);
        $this->assertSame('ada', $profile->suggestedDisplayName(), 'Sans nom, la partie de l\'adresse avant « @ ».');
    }

    #[DataProvider('fournisseurs')]
    public function testUnCodeRefuseEstUneErreur(string $class, string $authorize, string $token, string $userinfo): void
    {
        $this->expectException(OauthException::class);
        $this->expectExceptionMessage('invalid_grant');

        $this->client($class, [new JsonMockResponse(['error' => 'invalid_grant'], ['http_code' => 400])])->fetchProfile('code', 'https://forelse.test/retour');
    }

    #[DataProvider('fournisseurs')]
    public function testUnProfilSansIdentifiantEstUneErreur(string $class, string $authorize, string $token, string $userinfo): void
    {
        $this->expectException(OauthException::class);

        $this->client($class, [new JsonMockResponse(['access_token' => 'jeton']), new JsonMockResponse(['email' => 'ada@example.test'])])->fetchProfile('code', 'https://forelse.test/retour');
    }

    #[DataProvider('fournisseurs')]
    public function testDesactiveSansIdentifiants(string $class, string $authorize, string $token, string $userinfo): void
    {
        $this->assertFalse($this->client($class, [], '', '')->isEnabled());
        $this->assertFalse($this->client($class, [], 'id', '')->isEnabled());
        $this->assertTrue($this->client($class, [])->isEnabled());
    }
}
