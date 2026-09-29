<?php

namespace App\Tests\Account;

use App\Account\Github\GithubClient;
use App\Account\Github\GithubException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

final class GithubClientTest extends TestCase
{
    private const string CALLBACK = 'https://forelse.test/connexion/github/retour';

    /** @param list<array<string, mixed>> $emails */
    private function client(array $emails, array $token = ['access_token' => 'jeton']): GithubClient
    {
        return new GithubClient(new MockHttpClient([
            new JsonMockResponse($token),
            new JsonMockResponse(['id' => 7, 'login' => 'ada-l', 'name' => null]),
            new JsonMockResponse($emails),
        ]), 'id', 'secret');
    }

    public function testNeGardeQueLesAdressesVerifieesLaPrincipaleDAbord(): void
    {
        $profile = $this->client([
            ['email' => 'ada.pro@example.test', 'primary' => false, 'verified' => true],
            ['email' => 'pas-verifiee@example.test', 'primary' => false, 'verified' => false],
            ['email' => '7+ada-l@users.noreply.github.com', 'primary' => false, 'verified' => true],
            ['email' => 'Ada@Example.test', 'primary' => true, 'verified' => true],
        ])->fetchProfile('code', self::CALLBACK);

        $this->assertSame('7', $profile->id);
        $this->assertSame(['ada@example.test', 'ada.pro@example.test'], $profile->verifiedEmails);
        $this->assertSame('ada-l', $profile->suggestedDisplayName(), 'Sans nom affiché, l\'identifiant.');
    }

    public function testUnCodeRefuseParGithubEstUneErreur(): void
    {
        $this->expectException(GithubException::class);
        $this->expectExceptionMessage('bad_verification_code');

        $this->client([], ['error' => 'bad_verification_code'])->fetchProfile('code', self::CALLBACK);
    }

    public function testDesactiveSansIdentifiants(): void
    {
        $this->assertFalse((new GithubClient(new MockHttpClient(), '', ''))->isEnabled());
        $this->assertFalse((new GithubClient(new MockHttpClient(), 'id', ''))->isEnabled());
        $this->assertTrue((new GithubClient(new MockHttpClient(), 'id', 'secret'))->isEnabled());
    }
}
