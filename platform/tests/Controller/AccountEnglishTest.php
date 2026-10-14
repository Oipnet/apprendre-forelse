<?php

namespace App\Tests\Controller;

use App\Tests\DatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

/**
 * Le compte en anglais : inscription, connexion, mot de passe oublié et page du compte sous /en, et les e-mails
 * qu'ils envoient, dans la langue de la page où l'apprenant les a demandés.
 */
final class AccountEnglishTest extends WebTestCase
{
    use DatabaseTrait;

    public function testLesPagesDuCompteExistentEnAnglais(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        foreach (['/en/signup', '/en/forgot-password', '/en/login'] as $path) {
            $crawler = $client->request('GET', $path);
            $this->assertResponseIsSuccessful($path);
            $this->assertSame('en', $crawler->filter('html')->attr('lang'), $path);
        }
        $this->assertSelectorExists('a[href="/en/forgot-password"]', 'La connexion anglaise mène au mot de passe oublié anglais.');

        $client->loginUser($this->createUser());
        $client->request('GET', '/en/account');
        $this->assertResponseIsSuccessful();
    }

    /** Une page anglaise qui demande un compte renvoie vers la connexion anglaise, et y revient ensuite. */
    public function testLInscriptionAnglaiseEnvoieUnEmailEnAnglais(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('GET', '/en/signup?suite=/en/practice');
        $client->submitForm('Create my account', [
            'registration_form[displayName]' => 'Gorm',
            'registration_form[email]' => 'gorm@example.test',
            'registration_form[plainPassword]' => 'une-longue-phrase',
        ], serverParameters: ['HTTP_ORIGIN' => 'http://localhost']);

        $this->assertResponseRedirects('/en/practice');
        // L'un part à l'apprenant ; l'autre prévient l'administration (en français : elle n'a pas changé de langue).
        $this->assertEmailCount(2);
        $email = $this->getMailerMessage(1);
        $this->assertInstanceOf(Email::class, $email);
        $this->assertEmailAddressContains($email, 'To', 'gorm@example.test');
        $this->assertSame('Confirm your email address on default', $email->getSubject());
        $this->assertStringContainsString('http://localhost/en/account/confirm-email?', (string) $email->getTextBody(), 'Le lien ramène à la page anglaise.');
        $this->assertStringStartsWith('Hi Gorm,', trim((string) $email->getTextBody()));
    }

    public function testLeMotDePasseOublieEnAnglais(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $this->createUser('ada@example.test');

        $client->request('GET', '/en/forgot-password');
        $client->submitForm('Send me the link', ['reset_password_request_form[email]' => 'ada@example.test'], serverParameters: ['HTTP_ORIGIN' => 'http://localhost']);

        $this->assertResponseRedirects('/en/forgot-password/email-sent');
        $this->assertEmailCount(1);
        $email = $this->getMailerMessage();
        $this->assertSame('Choose a new password on default', $email?->getSubject());
        $this->assertStringContainsString('http://localhost/en/forgot-password/new/', (string) $email?->getTextBody());
    }
}
