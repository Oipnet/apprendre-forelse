<?php

namespace App\Tests\Controller;

use App\Tests\DatabaseTrait;
use App\Tests\PwnedPasswordsMock;
use App\Validator\StrongPassword;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

/**
 * La règle d'un nouveau mot de passe (App\Validator\StrongPassword), la même aux trois endroits où l'on en choisit
 * un : l'inscription, le compte, la réinitialisation. Have I Been Pwned est simulé (PwnedPasswordsMock), jamais appelé.
 */
final class PasswordRulesTest extends WebTestCase
{
    use DatabaseTrait;

    private const string ORIGIN = 'http://localhost';
    private const string CURRENT = 'une-longue-phrase';

    private KernelBrowser $client;
    /** Le formulaire de réinitialisation est affiché, jeton en session. */
    private bool $resetOuvert = false;

    protected function setUp(): void
    {
        PwnedPasswordsMock::reset();
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    /** @return iterable<string, array{string}> */
    public static function formulaires(): iterable
    {
        yield 'inscription' => ['inscription'];
        yield 'compte' => ['compte'];
        yield 'réinitialisation' => ['reinitialisation'];
    }

    #[DataProvider('formulaires')]
    public function testUnMotDePasseFacileADevinerEstRefuseAvecDeQuoiFaireMieux(string $formulaire): void
    {
        foreach (['motdepasse', '12345678', 'azertyuiop'] as $faible) {
            $this->choisir($formulaire, $faible);

            $this->assertResponseStatusCodeSame(422, $faible);
            $this->assertSelectorTextContains('.form-card, #mot-de-passe', 'phrase de passe', $faible);
        }
    }

    #[DataProvider('formulaires')]
    public function testUnMotDePasseCompromisEstRefuse(string $formulaire): void
    {
        PwnedPasswordsMock::$compromised = ['correct horse battery staple'];

        $this->choisir($formulaire, 'correct horse battery staple');

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('.form-card, #mot-de-passe', 'fuites de données connues');
    }

    #[DataProvider('formulaires')]
    public function testUnePhraseDePasseEstAcceptee(string $formulaire): void
    {
        $this->choisir($formulaire, 'quatre chats boivent du thé');

        $this->assertResponseRedirects();
    }

    /** Une panne de Have I Been Pwned ne bloque ni les inscriptions, ni les changements de mot de passe. */
    #[DataProvider('formulaires')]
    public function testSansReponseDeHaveIBeenPwnedLaPhraseEstAcceptee(string $formulaire): void
    {
        PwnedPasswordsMock::$down = true;

        $this->choisir($formulaire, 'quatre chats boivent du thé');

        $this->assertResponseRedirects();
    }

    public function testLesMessagesDisentQuoiFaire(): void
    {
        $this->assertStringContainsString('phrase de passe', StrongPassword::TOO_WEAK);
        $this->assertStringContainsString('phrase', StrongPassword::COMPROMISED);
    }

    private function choisir(string $formulaire, string $motDePasse): void
    {
        match ($formulaire) {
            'inscription' => $this->inscription($motDePasse),
            'compte' => $this->compte($motDePasse),
            'reinitialisation' => $this->reinitialisation($motDePasse),
        };
    }

    private function inscription(string $motDePasse): void
    {
        $this->client->request('GET', '/inscription');
        $this->client->submitForm('Créer mon compte', [
            'registration_form[displayName]' => 'Gorm',
            'registration_form[email]' => sprintf('gorm-%s@example.test', bin2hex(random_bytes(3))),
            'registration_form[plainPassword]' => $motDePasse,
        ], serverParameters: ['HTTP_ORIGIN' => self::ORIGIN]);
    }

    private function compte(string $motDePasse): void
    {
        $user = $this->utilisateur();
        $this->client->loginUser($user);
        $this->client->request('GET', '/compte');
        $this->client->submitForm('Changer le mot de passe', [
            'password_form[currentPassword]' => self::CURRENT,
            'password_form[plainPassword]' => $motDePasse,
        ], serverParameters: ['HTTP_ORIGIN' => self::ORIGIN]);
    }

    private function reinitialisation(string $motDePasse): void
    {
        // Un essai refusé laisse le formulaire affiché, jeton en session : on corrige sans redemander de lien.
        if (!$this->resetOuvert) {
            $this->resetOuvert = true;
            $token = static::getContainer()->get(ResetPasswordHelperInterface::class)->generateResetToken($this->utilisateur());
            $this->client->request('GET', '/mot-de-passe-oublie/nouveau/'.$token->getToken());
            $this->client->followRedirect();
        }
        $this->client->submitForm('Enregistrer et me connecter', ['change_password_form[plainPassword]' => $motDePasse], serverParameters: ['HTTP_ORIGIN' => self::ORIGIN]);
    }

    /** Ada, avec un mot de passe connu : créée une fois par test. */
    private function utilisateur(): \App\Entity\User
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = $entityManager->getRepository(\App\Entity\User::class)->findOneBy(['email' => 'ada@example.test']) ?? $this->createUser();
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::CURRENT));
        $entityManager->flush();

        return $user;
    }
}
