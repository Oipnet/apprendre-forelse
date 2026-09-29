<?php

namespace App\Tests\Controller;

use App\Account\Oauth\GithubClient;
use App\Account\Oauth\GoogleClient;
use App\Account\Oauth\LinkedinClient;
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

/**
 * Google et LinkedIn, de bout en bout, et les règles entre plusieurs fournisseurs ; les fournisseurs sont simulés
 * (MockHttpClient), jamais appelés. Le parcours complet de GitHub est dans GithubTest.
 */
final class OauthProvidersTest extends WebTestCase
{
    use DatabaseTrait;

    private const array ORIGIN = ['HTTP_ORIGIN' => 'http://localhost'];

    private KernelBrowser $client;
    /** Ce que répond le point « userinfo » de Google et de LinkedIn. */
    private array $userinfo = ['sub' => 'g-1', 'email' => 'Ada@Example.test', 'email_verified' => true, 'name' => 'Ada Lovelace'];
    private int $calls = 0;

    /** Démarre le navigateur avec Google et LinkedIn simulés, et GitHub configuré ou non. */
    private function start(bool $withGoogle = true, bool $withLinkedin = true): void
    {
        $this->client = static::createClient();
        // Les faux fournisseurs sont des services remplacés : ils doivent survivre d'une requête à l'autre.
        $this->client->disableReboot();
        $this->resetDatabase();
        $http = new MockHttpClient(function (string $method, string $url): JsonMockResponse {
            ++$this->calls;

            return str_contains($url, 'userinfo') ? new JsonMockResponse($this->userinfo) : new JsonMockResponse(['access_token' => 'jeton']);
        });
        $container = static::getContainer();
        $container->set(GoogleClient::class, new GoogleClient($http, $withGoogle ? 'id' : '', $withGoogle ? 'secret' : ''));
        $container->set(LinkedinClient::class, new LinkedinClient($http, $withLinkedin ? 'id' : '', $withLinkedin ? 'secret' : ''));
        $container->set(GithubClient::class, new GithubClient(new MockHttpClient(), '', ''));
    }

    /** Aller chez le fournisseur puis revenir, comme le navigateur : le jeton « state » fait l'aller-retour. */
    private function roundTrip(string $provider, array $query = []): void
    {
        $this->client->request('GET', '/connexion/'.$provider, $query);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $params);
        $this->assertSame('http://localhost/connexion/'.$provider.'/retour', $params['redirect_uri']);

        $this->client->request('GET', '/connexion/'.$provider.'/retour', ['code' => 'code', 'state' => $params['state']]);
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function identity(string $provider, string $id): ?ExternalIdentity
    {
        return static::getContainer()->get(ExternalIdentityRepository::class)->findOneByProviderId($provider, $id);
    }

    /** Un compte sans mot de passe, lié aux fournisseurs donnés (nom => identifiant chez lui). */
    private function passwordlessUser(array $identities): User
    {
        $user = (new User())->setEmail('ada@example.test')->setDisplayName('Ada')->confirmEmail('ada@example.test', new \DateTimeImmutable());
        $this->entityManager()->persist($user);
        foreach ($identities as $provider => $id) {
            $this->entityManager()->persist(new ExternalIdentity($user, $provider, $id, 'ada', new \DateTimeImmutable()));
        }
        $this->entityManager()->flush();

        return $user;
    }

    public function testUnBoutonParFournisseurConfigure(): void
    {
        $this->start(withLinkedin: false);

        $crawler = $this->client->request('GET', '/connexion');

        $this->assertSame(['Continuer avec Google'], $crawler->filter('.oauth a')->each(static fn ($a) => $a->text()));
        $this->client->request('GET', '/connexion/linkedin');
        $this->assertResponseStatusCodeSame(404, 'Sans identifiants, pas de route.');
        $this->client->request('GET', '/confidentialite');
        $this->assertSelectorTextContains('main', 'Google Ireland Limited');
        $this->assertSelectorTextNotContains('main', 'LinkedIn Ireland');
    }

    public function testUnePremiereConnexionGoogleCreeLeCompteSansMotDePasse(): void
    {
        $this->start();

        $this->roundTrip('google');
        $this->assertResponseRedirects('/inscription/google');
        $crawler = $this->client->followRedirect();
        $this->assertSelectorTextContains('.form-card', 'ada@example.test');
        $this->assertSame('Ada Lovelace', $crawler->filter('#external_registration_form_displayName')->attr('value'));

        $this->client->submitForm('Créer mon compte', ['external_registration_form[displayName]' => 'Ada L.'], serverParameters: self::ORIGIN);
        $this->assertResponseRedirects('/');
        $user = static::getContainer()->get(UserRepository::class)->findOneByEmail('ada@example.test');
        $this->assertNotNull($user);
        $this->assertFalse($user->hasPassword());
        $this->assertTrue($user->isEmailVerified());
        $this->assertSame($user->getId(), $this->identity('google', 'g-1')?->getUser()->getId());
    }

    public function testLinkedinRattacheUnCompteConfirmeParSonAdresse(): void
    {
        $this->start();
        $user = $this->createUser('ada@example.test')->confirmEmail('ada@example.test', new \DateTimeImmutable());
        $this->entityManager()->flush();
        $this->userinfo['sub'] = 'li-7';

        $this->roundTrip('linkedin');

        $this->assertResponseRedirects('/');
        $this->assertSame($user->getId(), $this->identity('linkedin', 'li-7')?->getUser()->getId());
    }

    public function testUneAdresseQueLinkedinNAPasVerifieeNeRattacheRien(): void
    {
        $this->start();
        $this->createUser('ada@example.test')->confirmEmail('ada@example.test', new \DateTimeImmutable());
        $this->entityManager()->flush();
        $this->userinfo = ['sub' => 'li-7', 'email' => 'ada@example.test', 'email_verified' => false, 'name' => 'Ada'];

        $this->roundTrip('linkedin');

        $this->assertResponseRedirects('/connexion');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-error', 'Votre compte LinkedIn n\'a aucune adresse email vérifiée');
        $this->assertNull($this->identity('linkedin', 'li-7'));
    }

    public function testLEtatDUnFournisseurNeSertPasPourUnAutre(): void
    {
        $this->start();
        $this->client->request('GET', '/connexion/google');
        parse_str((string) parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $params);

        $this->client->request('GET', '/connexion/linkedin/retour', ['code' => 'code', 'state' => $params['state']]);

        $this->assertResponseRedirects('/connexion');
        $this->assertSame(0, $this->calls);
    }

    public function testSansMotDePasseOnDelieUnFournisseurSIlEnResteUnAutre(): void
    {
        $this->start();
        $user = $this->passwordlessUser(['google' => 'g-1', 'linkedin' => 'li-7']);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/compte');
        $this->assertSelectorTextContains('#mot-de-passe', 'Google ou LinkedIn');
        $this->client->submit($crawler->selectButton('Délier Google')->form(), serverParameters: self::ORIGIN);
        $this->assertResponseRedirects('/compte#profil');
        $this->assertNull($this->identity('google', 'g-1'));

        $this->client->request('GET', '/compte');
        $this->assertSelectorNotExists('#linkedin button', 'Le dernier moyen de connexion ne se délie plus.');
        $this->client->request('POST', '/compte/linkedin/delier', ['_token' => 'csrf-token'], server: self::ORIGIN);
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-error', 'Choisissez d\'abord un mot de passe');
        $this->assertNotNull($this->identity('linkedin', 'li-7'));
    }

    public function testConfirmerSonIdentiteDemandeUnFournisseurDejaLie(): void
    {
        // Ada n'a que LinkedIn. Quelqu'un qui aurait sa session ne doit pas pouvoir « confirmer » en liant son
        // propre compte Google, puis changer l'adresse ou supprimer le compte.
        $this->start();
        $user = $this->passwordlessUser(['linkedin' => 'li-7']);
        $id = $user->getId();
        $this->client->loginUser($user);
        $this->userinfo['sub'] = 'g-intrus';

        $this->roundTrip('google', ['mode' => 'confirmer', 'suite' => '/compte#suppression']);
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-error', 'identité non confirmée');
        $this->assertNull($this->identity('google', 'g-intrus'), 'Rien n\'a été lié.');

        $crawler = $this->client->request('GET', '/compte');
        $this->assertSelectorExists('#suppression a[href^="/connexion/linkedin?mode=confirmer"]', 'On propose LinkedIn, le seul qui lui est lié.');
        $this->assertSelectorNotExists('#suppression a[href^="/connexion/google"]');
        $this->client->submit($crawler->selectButton('Supprimer définitivement')->form(), serverParameters: self::ORIGIN);
        $this->assertResponseStatusCodeSame(422);

        $this->userinfo['sub'] = 'li-7';
        $this->roundTrip('linkedin', ['mode' => 'confirmer', 'suite' => '/compte#suppression']);
        $crawler = $this->client->request('GET', '/compte');
        $this->client->submit($crawler->selectButton('Supprimer définitivement')->form(), serverParameters: self::ORIGIN);
        $this->assertResponseRedirects('/');
        $this->entityManager()->clear();
        $this->assertNull(static::getContainer()->get(UserRepository::class)->find($id));
    }

    public function testUnFournisseurRetireDeLaConfigurationSeDelieEncore(): void
    {
        $this->start(withLinkedin: false);
        $user = $this->createUser('ada@example.test');
        $this->entityManager()->persist(new ExternalIdentity($user, 'linkedin', 'li-7', 'ada', new \DateTimeImmutable()));
        $this->entityManager()->flush();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/compte');
        $this->client->submit($crawler->selectButton('Délier LinkedIn')->form(), serverParameters: self::ORIGIN);

        $this->assertResponseRedirects('/compte#profil');
        $this->assertNull($this->identity('linkedin', 'li-7'));
    }
}
