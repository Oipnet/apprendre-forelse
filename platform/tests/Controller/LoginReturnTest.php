<?php

namespace App\Tests\Controller;

use App\Tests\DatabaseTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** La connexion ramène là d'où l'on vient (?suite=), et « Rester connecté·e » pose le cookie de longue durée. */
final class LoginReturnTest extends WebTestCase
{
    use DatabaseTrait;

    private const string PASSWORD = 'une-longue-phrase';
    private const string EXERCISE = '/parcours/decouverte/01-bonjour';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
        $user = $this->createUser();
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        static::getContainer()->get('doctrine')->getManager()->flush();
    }

    public function testSeConnecterDepuisUnExerciceVerrouilleRameneAcetExercice(): void
    {
        $crawler = $this->client->request('GET', self::EXERCISE);
        $this->client->click($crawler->filter('.exercise-access a')->reduce(static fn ($a) => 'Se connecter' === trim($a->text()))->link());

        $this->submit(self::PASSWORD);

        $this->assertResponseRedirects('http://localhost'.self::EXERCISE);
        $this->client->followRedirect();
        $this->assertSelectorExists('[data-playground]', 'Connecté·e, l\'exercice s\'ouvre.');
    }

    public function testUnEchecGardeLaPageDOrigine(): void
    {
        $this->client->request('GET', '/connexion?suite='.self::EXERCISE);
        $this->submit('faux');
        $this->assertResponseRedirects('http://localhost/connexion?suite='.self::EXERCISE);

        $this->client->followRedirect();
        $this->assertSelectorExists('.alert[role="alert"]', 'L\'erreur est annoncée.');
        $this->submit(self::PASSWORD);
        $this->assertResponseRedirects('http://localhost'.self::EXERCISE);
    }

    public function testUneAdresseDUnAutreSiteEstIgnoree(): void
    {
        $this->client->request('GET', '/connexion?suite=//ailleurs.example/piege');
        $this->assertSelectorNotExists('input[name="_target_path"]');

        $this->submit(self::PASSWORD);
        $this->assertResponseRedirects('http://localhost/');
    }

    public function testDejaConnecteeLaConnexionMeneALaPageDOrigine(): void
    {
        $this->client->request('GET', '/connexion');
        $this->submit(self::PASSWORD);

        $this->client->request('GET', '/connexion?suite='.self::EXERCISE);
        $this->assertResponseRedirects(self::EXERCISE);
    }

    public function testResterConnecteePoseLeCookieDeLongueDuree(): void
    {
        $this->client->request('GET', '/connexion');
        $this->submit(self::PASSWORD);
        $this->assertNull($this->client->getCookieJar()->get('REMEMBERME'), 'Sans la case : la session seule.');

        $this->client->getCookieJar()->clear(); // une autre visite, sans session
        $this->client->request('GET', '/connexion');
        $this->submit(self::PASSWORD, remember: true);
        $this->assertNotNull($this->client->getCookieJar()->get('REMEMBERME'));
    }

    private function submit(string $password, bool $remember = false): void
    {
        $form = $this->client->getCrawler()->selectButton('Se connecter')->form(['email' => 'ada@example.test', 'password' => $password]);
        if ($remember) {
            $form['_remember_me']->tick();
        }
        $this->client->submit($form, serverParameters: ['HTTP_ORIGIN' => 'http://localhost']);
    }
}
