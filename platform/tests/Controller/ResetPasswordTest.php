<?php

namespace App\Tests\Controller;

use App\Repository\ResetPasswordRequestRepository;
use App\Tests\DatabaseTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

final class ResetPasswordTest extends WebTestCase
{
    use DatabaseTrait;

    public function testDemandeEmailNouveauMotDePassePuisConnexion(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $this->createUser('ada@example.test');

        $client->request('GET', '/connexion');
        $client->clickLink('Mot de passe oublié ?');
        $this->assertResponseIsSuccessful();
        $client->submitForm('Recevoir le lien', ['reset_password_request_form[email]' => 'ada@example.test'], serverParameters: ['HTTP_ORIGIN' => 'http://localhost']);

        $this->assertResponseRedirects('/mot-de-passe-oublie/email-envoye');
        $this->assertEmailCount(1);
        $email = $this->getMailerMessage();
        $this->assertInstanceOf(Email::class, $email);
        $this->assertEmailAddressContains($email, 'to', 'ada@example.test');
        $this->assertEmailAddressContains($email, 'from', 'ne-pas-repondre@forelse.fr');
        $this->assertEmailTextBodyContains($email, 'Ada');
        $this->assertSame(1, preg_match('#http://localhost/mot-de-passe-oublie/nouveau/(\S+)#', (string) $email->getTextBody(), $match), 'Le texte contient le lien.');
        $this->assertEmailHtmlBodyContains($email, $match[0]);
        $link = $match[0];

        $client->followRedirect();
        $this->assertSelectorTextContains('.form-card', 'un email vient de partir');

        // Le jeton passe en session puis disparaît de l'URL.
        $client->request('GET', $link);
        $this->assertResponseRedirects('/mot-de-passe-oublie/nouveau');
        $client->followRedirect();
        $client->submitForm('Enregistrer et me connecter', ['change_password_form[plainPassword]' => 'une-nouvelle-phrase'], serverParameters: ['HTTP_ORIGIN' => 'http://localhost']);

        $this->assertResponseRedirects('/');
        $client->followRedirect();
        $this->assertSelectorTextContains('.flash-success', 'Mot de passe modifié');
        $this->assertSelectorTextContains('.lp-user', 'Ada', 'Connectée dans la foulée.');
        $this->assertCount(0, static::getContainer()->get(ResetPasswordRequestRepository::class)->findAll(), 'La demande est consommée.');

        // Le nouveau mot de passe fonctionne, le lien ne sert qu'une fois.
        $client->request('GET', '/deconnexion');
        $this->login($client, 'ada@example.test', 'une-nouvelle-phrase');
        $this->assertResponseRedirects('/');

        $client->request('GET', '/deconnexion');
        $client->request('GET', $link);
        $client->followRedirect();
        $this->assertResponseRedirects('/mot-de-passe-oublie');
        $client->followRedirect();
        $this->assertSelectorTextContains('.flash-error', 'Ce lien de réinitialisation n\'est pas valide, ou a déjà servi.');
    }

    public function testUnEmailInconnuNeRevelRien(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('GET', '/mot-de-passe-oublie');
        $client->submitForm('Recevoir le lien', ['reset_password_request_form[email]' => 'inconnu@example.test'], serverParameters: ['HTTP_ORIGIN' => 'http://localhost']);

        $this->assertResponseRedirects('/mot-de-passe-oublie/email-envoye');
        $this->assertEmailCount(0);
        $client->followRedirect();
        $this->assertSelectorTextContains('.form-card', 'Si un compte existe avec cette adresse');
    }

    public function testUneSecondeDemandeDansLHeureNEnvoieRien(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $this->createUser('ada@example.test');

        foreach ([1, 0] as $expected) {
            $client->request('GET', '/mot-de-passe-oublie');
            $client->submitForm('Recevoir le lien', ['reset_password_request_form[email]' => 'ada@example.test'], serverParameters: ['HTTP_ORIGIN' => 'http://localhost']);
            $this->assertResponseRedirects('/mot-de-passe-oublie/email-envoye');
            $this->assertEmailCount($expected);
        }
    }

    public function testUnJetonInventeEstRefuse(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('GET', '/mot-de-passe-oublie/nouveau/'.str_repeat('a', 40));
        $client->followRedirect();
        $this->assertResponseRedirects('/mot-de-passe-oublie');

        $client->request('GET', '/mot-de-passe-oublie/nouveau');
        $this->assertResponseStatusCodeSame(404, 'Sans jeton en session, rien à réinitialiser.');
    }

    private function login(KernelBrowser $client, string $email, string $password): void
    {
        $client->request('GET', '/connexion');
        $client->submitForm('Se connecter', ['email' => $email, 'password' => $password], serverParameters: ['HTTP_ORIGIN' => 'http://localhost']);
    }
}
