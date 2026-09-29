<?php

namespace App\Tests\Controller;

use App\Account\Oauth\GithubClient;
use App\Entity\ExternalIdentity;
use App\Entity\User;
use App\Repository\ExternalIdentityRepository;
use App\Repository\UserRepository;
use App\Tests\DatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Connexion avec GitHub, de bout en bout ; GitHub est simulé (MockHttpClient), jamais appelé. */
final class GithubTest extends WebTestCase
{
    use DatabaseTrait;

    private const array ORIGIN = ['HTTP_ORIGIN' => 'http://localhost'];

    private KernelBrowser $client;
    /** Ce que le faux GitHub répond : profil et adresses. */
    private array $githubUser = ['id' => 4242, 'login' => 'ada-l', 'name' => 'Ada Lovelace'];
    /** @var list<array{email: string, primary: bool, verified: bool}> */
    private array $githubEmails = [['email' => 'Ada@Example.test', 'primary' => true, 'verified' => true]];
    private int $githubCalls = 0;

    /** @var array<string, array<string, string|null>> valeurs d'origine des variables d'env surchargées */
    private array $originalEnv = [];

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $store => $values) {
            foreach ($values as $name => $value) {
                if (null === $value) {
                    unset($GLOBALS[$store][$name]);
                } else {
                    $GLOBALS[$store][$name] = $value;
                }
            }
        }
        $this->originalEnv = [];
        parent::tearDown();
    }

    /** Démarre le navigateur, avec le faux GitHub (ou sans configuration GitHub). */
    private function start(bool $configured = true): void
    {
        $this->client = static::createClient();
        // Le faux GitHub est un service remplacé : il doit survivre d'une requête à l'autre.
        $this->client->disableReboot();
        $this->resetDatabase();
        $http = new MockHttpClient(function (string $method, string $url): JsonMockResponse {
            ++$this->githubCalls;

            return match (true) {
                str_ends_with($url, '/login/oauth/access_token') => new JsonMockResponse(['access_token' => 'jeton', 'token_type' => 'bearer']),
                str_ends_with($url, '/user/emails') => new JsonMockResponse($this->githubEmails),
                str_ends_with($url, '/user') => new JsonMockResponse($this->githubUser),
                default => throw new \LogicException('Appel inattendu : '.$url),
            };
        });
        static::getContainer()->set(GithubClient::class, new GithubClient($http, $configured ? 'id-de-test' : '', $configured ? 'secret-de-test' : ''));
    }

    private function setEnv(string $name, string $value): void
    {
        foreach (['_ENV', '_SERVER'] as $store) {
            $this->originalEnv[$store][$name] ??= $GLOBALS[$store][$name] ?? null;
            $GLOBALS[$store][$name] = $value;
        }
    }

    /** Aller chez GitHub puis revenir, comme le navigateur : le jeton « state » fait l'aller-retour. */
    private function roundTrip(array $query = []): void
    {
        $this->client->request('GET', '/connexion/github', $query);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        $this->assertStringStartsWith('https://github.com/login/oauth/authorize?', $location);
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $params);
        $this->assertSame('http://localhost/connexion/github/retour', $params['redirect_uri']);
        $this->assertSame('read:user user:email', $params['scope']);

        $this->client->request('GET', '/connexion/github/retour', ['code' => 'code-de-github', 'state' => $params['state']]);
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function identity(): ?ExternalIdentity
    {
        return static::getContainer()->get(ExternalIdentityRepository::class)->findOneByProviderId(ExternalIdentity::GITHUB, '4242');
    }

    private function verifiedUser(string $email = 'ada@example.test', bool $withPassword = true): User
    {
        $user = $this->createUser($email)->confirmEmail($email, new \DateTimeImmutable());
        if ($withPassword) {
            $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'une-longue-phrase'));
        }
        $this->entityManager()->flush();

        return $user;
    }

    /** Un compte créé avec GitHub : sans mot de passe, lié au compte GitHub 4242. */
    private function githubOnlyUser(): User
    {
        $user = (new User())->setEmail('ada@example.test')->setDisplayName('Ada')->confirmEmail('ada@example.test', new \DateTimeImmutable());
        $this->entityManager()->persist($user);
        $this->entityManager()->persist(new ExternalIdentity($user, ExternalIdentity::GITHUB, '4242', 'ada-l', new \DateTimeImmutable()));
        $this->entityManager()->flush();

        return $user;
    }

    public function testUnePremiereConnexionCreeLeCompteSansMotDePasse(): void
    {
        $this->start();
        $this->client->request('GET', '/connexion');
        $this->assertSelectorExists('a[href="/connexion/github"]');

        $this->roundTrip();
        $this->assertResponseRedirects('/inscription/github');
        $crawler = $this->client->followRedirect();
        $this->assertSelectorTextContains('.form-card', 'ada-l');
        $this->assertSelectorTextContains('.form-card', 'ada@example.test');
        $this->assertSame('Ada Lovelace', $crawler->filter('#external_registration_form_displayName')->attr('value'), 'Pseudo proposé d\'après GitHub.');

        $this->client->submitForm('Créer mon compte', ['external_registration_form[displayName]' => 'Ada L.'], serverParameters: self::ORIGIN);
        $this->assertResponseRedirects('/');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.lp-user', 'Ada L.', 'Connectée dans la foulée.');

        $user = static::getContainer()->get(UserRepository::class)->findOneByEmail('ada@example.test');
        $this->assertNotNull($user);
        $this->assertFalse($user->hasPassword());
        $this->assertTrue($user->isEmailVerified(), 'Adresse vérifiée par GitHub : pas d\'email de confirmation.');
        $this->assertSame($user->getId(), $this->identity()?->getUser()->getId());
    }

    public function testLaConnexionSuivanteRetrouveLeCompteMemeSiLAdresseAChangeChezGithub(): void
    {
        $this->start();
        $user = $this->githubOnlyUser();
        $this->githubEmails = [['email' => 'ada.nouvelle@example.test', 'primary' => true, 'verified' => true]];
        $this->githubUser['login'] = 'ada-renommee';

        $this->roundTrip();

        $this->assertResponseRedirects('/');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.lp-user', 'Ada');
        $this->assertSame('ada-renommee', $this->identity()?->getUsername(), 'Le nom d\'utilisateur suit GitHub.');
        $this->assertSame('ada@example.test', static::getContainer()->get(UserRepository::class)->find($user->getId())?->getEmail(), 'L\'adresse du compte ne change pas.');
    }

    public function testUnCompteConfirmeEstRattacheParSonAdresseQuelleQueSoitLaCasse(): void
    {
        $this->start();
        $user = $this->verifiedUser();

        $this->roundTrip();

        $this->assertResponseRedirects('/');
        $this->assertSame($user->getId(), $this->identity()?->getUser()->getId());
    }

    public function testUneAdresseQueGithubNAPasVerifieeNeRattacheRien(): void
    {
        $this->start();
        $this->verifiedUser();
        $this->githubEmails = [
            ['email' => 'ada@example.test', 'primary' => true, 'verified' => false],
            ['email' => '4242+ada-l@users.noreply.github.com', 'primary' => false, 'verified' => true],
        ];

        $this->roundTrip();

        $this->assertResponseRedirects('/connexion');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-error', 'aucune adresse email vérifiée');
        $this->assertNull($this->identity());
    }

    public function testUnCompteDontLAdresseNEstPasConfirmeeNEstPasRattache(): void
    {
        // Quelqu'un a inscrit l'adresse d'Ada avec un mot de passe à lui : Ada, en passant par GitHub, ne doit pas
        // lier son GitHub à ce compte-là.
        $this->start();
        $this->createUser('ada@example.test');

        $this->roundTrip();

        $this->assertResponseRedirects('/connexion');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-error', 'n\'a jamais été confirmée');
        $this->assertNull($this->identity());
        $this->assertSelectorNotExists('.lp-user');
    }

    public function testUnEtatFalsifieEstRefuseSansAppelerGithub(): void
    {
        $this->start();
        $this->client->request('GET', '/connexion/github');

        $this->client->request('GET', '/connexion/github/retour', ['code' => 'code', 'state' => 'invente']);

        $this->assertResponseRedirects('/connexion');
        $this->assertSame(0, $this->githubCalls);
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-error', 'expiré');
    }

    public function testUnRefusChezGithubRevientALaConnexion(): void
    {
        $this->start();
        $this->client->request('GET', '/connexion/github');
        parse_str((string) parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $params);

        $this->client->request('GET', '/connexion/github/retour', ['error' => 'access_denied', 'state' => $params['state']]);

        $this->assertResponseRedirects('/connexion');
        $this->assertSame(0, $this->githubCalls);
    }

    public function testInscriptionSurInvitationLeCodeEstExige(): void
    {
        $this->setEnv('REGISTRATION_INVITE_ONLY', '1');
        $this->start();
        $this->createCohort('PROMO-A');

        $this->roundTrip();
        $this->client->followRedirect();
        $this->client->submitForm('Créer mon compte', ['external_registration_form[displayName]' => 'Ada'], serverParameters: self::ORIGIN);
        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('.form-card', 'Indiquez votre code d\'invitation.');

        $this->client->submitForm('Créer mon compte', ['external_registration_form[displayName]' => 'Ada', 'external_registration_form[invitationCode]' => 'PROMO-A'], serverParameters: self::ORIGIN);
        $this->assertResponseRedirects('/');
        $this->assertSame('promo-a', static::getContainer()->get(UserRepository::class)->findOneByEmail('ada@example.test')?->getCohort()?->getCode(), 'Code saisi en majuscules, enregistré normalisé.');
    }

    public function testSansConfigurationPasDeBoutonNiDeRoute(): void
    {
        $this->start(configured: false);

        $this->client->request('GET', '/connexion');
        $this->assertSelectorNotExists('a[href="/connexion/github"]');
        $this->client->request('GET', '/inscription');
        $this->assertSelectorTextNotContains('.form-card', 'GitHub');
        $this->client->request('GET', '/connexion/github');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testLierPuisDelierDepuisLeCompte(): void
    {
        $this->start();
        $user = $this->verifiedUser('autre@example.test');
        $this->client->loginUser($user);

        $this->roundTrip(['mode' => 'lier']);
        $this->assertResponseRedirects('/compte#profil');
        $this->assertSame($user->getId(), $this->identity()?->getUser()->getId(), 'Lié malgré une adresse différente : c\'est le titulaire connecté qui le demande.');

        $crawler = $this->client->request('GET', '/compte');
        $this->assertSelectorTextContains('#github', 'ada-l');
        $this->client->submit($crawler->selectButton('Délier GitHub')->form(), serverParameters: self::ORIGIN);
        $this->assertResponseRedirects('/compte#profil');
        $this->assertNull($this->identity());
    }

    public function testUnCompteGithubDejaLieAUnAutreCompteNEstPasVole(): void
    {
        $this->start();
        $this->githubOnlyUser();
        $other = $this->verifiedUser('autre@example.test');
        $this->client->loginUser($other);

        $this->roundTrip(['mode' => 'lier']);

        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-error', 'lié à un autre compte');
        $this->assertNotSame($other->getId(), $this->identity()?->getUser()->getId());
    }

    public function testSansMotDePasseOnNePeutPasDelierSonSeulMoyenDeConnexion(): void
    {
        $this->start();
        $user = $this->githubOnlyUser();
        $this->client->loginUser($user);

        $this->client->request('GET', '/compte');
        $this->assertSelectorNotExists('#github button');
        $this->assertSelectorTextContains('#mot-de-passe', 'Mot de passe oublié');

        $this->client->request('POST', '/compte/github/delier', ['_token' => 'csrf-token'], server: self::ORIGIN);
        $this->assertResponseRedirects('/compte#profil');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-error', 'Choisissez d\'abord un mot de passe');
        $this->assertNotNull($this->identity());
    }

    public function testSansMotDePasseSupprimerLeCompteDemandeDeRepasserParGithub(): void
    {
        $this->start();
        $user = $this->githubOnlyUser();
        $id = $user->getId();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/compte');
        $this->client->submit($crawler->selectButton('Supprimer définitivement')->form(), serverParameters: self::ORIGIN);
        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('#suppression', 'Confirmez d\'abord votre identité avec GitHub');
        $this->assertNotNull(static::getContainer()->get(UserRepository::class)->find($id));

        $this->roundTrip(['mode' => 'confirmer', 'suite' => '/compte#suppression']);
        $this->assertResponseRedirects('/compte#suppression');
        $crawler = $this->client->request('GET', '/compte');
        $this->client->submit($crawler->selectButton('Supprimer définitivement')->form(), serverParameters: self::ORIGIN);
        $this->assertResponseRedirects('/');
        $this->entityManager()->clear();
        $this->assertNull(static::getContainer()->get(UserRepository::class)->find($id));
        $this->assertNull($this->identity(), 'Le lien part avec le compte.');
    }

    public function testConfirmerAvecUnAutreCompteGithubNeConfirmeRien(): void
    {
        $this->start();
        $user = $this->githubOnlyUser();
        $this->client->loginUser($user);
        $this->githubUser['id'] = 9999;

        $this->roundTrip(['mode' => 'confirmer', 'suite' => '/compte#suppression']);

        $crawler = $this->client->request('GET', '/compte');
        $this->client->submit($crawler->selectButton('Supprimer définitivement')->form(), serverParameters: self::ORIGIN);
        $this->assertResponseStatusCodeSame(422);
    }
}
