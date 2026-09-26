<?php

namespace App\Tests\Controller;

use App\Tests\DatabaseTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** La connexion se bloque après 5 échecs en 15 minutes (login_throttling du pare-feu main). */
final class LoginThrottlingTest extends WebTestCase
{
    use DatabaseTrait;

    private const string PASSWORD = 'une-longue-phrase';

    public function testCinqEchecsBloquentLaConnexionMemeAvecLeBonMotDePasse(): void
    {
        $client = static::createClient();
        // Les compteurs sont dans la base, remise à zéro à chaque test.
        $this->resetDatabase();
        $user = $this->createUser();
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        static::getContainer()->get('doctrine')->getManager()->flush();

        for ($i = 1; $i <= 5; ++$i) {
            $this->login($client, 'faux-'.$i);
            $client->followRedirect();
            $this->assertSelectorTextNotContains('.alert', 'Trop de tentatives', 'Échec n° '.$i.' : pas encore bloqué.');
        }

        $this->login($client, self::PASSWORD);
        $client->followRedirect();
        $this->assertSelectorTextContains('.alert', 'Trop de tentatives de connexion');
        $client->request('GET', '/compte');
        $this->assertResponseRedirects('/connexion', message: 'Toujours pas connectée.');
    }

    private function login(KernelBrowser $client, string $password): void
    {
        $client->request('GET', '/connexion');
        $client->submitForm('Se connecter', ['email' => 'ada@example.test', 'password' => $password], serverParameters: ['HTTP_ORIGIN' => 'http://localhost']);
    }
}
