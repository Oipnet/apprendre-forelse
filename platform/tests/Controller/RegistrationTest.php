<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\DatabaseTrait;
use App\Tests\PacksTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

final class RegistrationTest extends WebTestCase
{
    use DatabaseTrait;
    use PacksTrait;

    /** @var array<string, array<string, string|null>> valeurs d'origine des variables d'env surchargées, par magasin ($_ENV, $_SERVER) */
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
        $this->restorePacks();
        parent::tearDown();
    }

    /** Les variables d'env sont lues à l'exécution : on les pose avant de démarrer le kernel. */
    private function setEnv(string $name, string $value): void
    {
        foreach (['_ENV', '_SERVER'] as $store) {
            $this->originalEnv[$store][$name] ??= $GLOBALS[$store][$name] ?? null;
            $GLOBALS[$store][$name] = $value;
        }
    }

    /** Bêta fermée. */
    private function inviteOnly(): void
    {
        $this->setEnv('REGISTRATION_INVITE_ONLY', '1');
    }

    public function testInscriptionPuisRetourALExerciceVise(): void
    {
        $this->usePaidPack();
        $client = static::createClient();
        $this->resetDatabase();

        // L'invité lit un exercice du deuxième chapitre, puis s'inscrit depuis sa page : il doit y revenir.
        $crawler = $client->request('GET', '/parcours/payant/e2');
        $client->click($crawler->filter('.exercise-access')->selectLink('Créer un compte')->link());
        $client->submitForm('Créer mon compte', [
            'registration_form[displayName]' => 'Gorm',
            'registration_form[email]' => 'gorm@example.test',
            'registration_form[plainPassword]' => 'une-longue-phrase',
        ], serverParameters: ['HTTP_ORIGIN' => 'http://localhost']);

        $this->assertResponseRedirects('/parcours/payant/e2');
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'gorm@example.test']);
        $this->assertNotNull($user);
        $this->assertNotSame('une-longue-phrase', $user->getPassword(), 'Le mot de passe est haché.');

        $client->followRedirect();
        $this->assertResponseIsSuccessful('Connecté après inscription (parcours sans tarif : ouvert à tout compte).');
    }

    public function testUnEmailNePeutServirQuUneFois(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $this->createUser('gorm@example.test');

        $client->request('GET', '/inscription');
        $client->submitForm('Créer mon compte', [
            'registration_form[displayName]' => 'Gorm',
            'registration_form[email]' => 'gorm@example.test',
            'registration_form[plainPassword]' => 'une-longue-phrase',
        ], serverParameters: ['HTTP_ORIGIN' => 'http://localhost']);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('.form-card', User::EMAIL_TAKEN);

        // Le titulaire du compte apprend la tentative.
        $this->assertEmailCount(1);
        $email = $this->getMailerMessage();
        $this->assertInstanceOf(Email::class, $email);
        $this->assertEmailAddressContains($email, 'To', 'gorm@example.test');
        $this->assertEmailTextBodyContains($email, 'essayer de créer un compte');
    }

    public function testLeTitulaireNEstPrevenuQuUneFoisParJour(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $holder = $this->createUser('gorm@example.test');
        // Les compteurs vivent dans un cache en mémoire, vidé entre deux requêtes de test : on épuise le quota avant
        // la première requête, sur le même kernel.
        static::getContainer()->get('limiter.registration_notice')->create((string) $holder->getId())->consume();

        $this->register($client, 'gorm@example.test');

        $this->assertResponseStatusCodeSame(422);
        $this->assertEmailCount(0);
    }

    public function testTropDEmailsDejaPrisBloquentToutesLesInscriptions(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $limiter = static::getContainer()->get('limiter.registration_duplicate')->create('127.0.0.1');
        for ($i = 1; $i <= 10; ++$i) {
            $limiter->consume();
        }

        // Même avec un email libre : sinon, la réussite trahirait qu'il n'avait pas de compte.
        $this->register($client, 'libre@example.test');

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('.form-card', 'réessayez dans une heure');
        $this->assertNull(static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'libre@example.test']));
    }

    public function testLesInscriptionsReussiesNeRapprochentPasDeLaLimite(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        // Une classe entière s'inscrit derrière la même adresse IP : seuls les refus pour email déjà pris comptent.
        $limiter = static::getContainer()->get('limiter.registration_duplicate')->create('127.0.0.1');
        for ($i = 1; $i <= 9; ++$i) {
            $limiter->consume();
        }

        $this->register($client, 'eleve@example.test');

        $this->assertResponseRedirects('/');
        $this->assertSame(1, $limiter->consume(0)->getRemainingTokens());
    }

    /** Envoi direct, sans charger le formulaire : c'est la première requête, sur le kernel des compteurs épuisés. */
    private function register(KernelBrowser $client, string $email): void
    {
        $client->request('POST', '/inscription', ['registration_form' => [
            'displayName' => 'Gorm',
            'email' => $email,
            'plainPassword' => 'une-longue-phrase',
            '_token' => 'csrf-token',
        ]], server: ['HTTP_ORIGIN' => 'http://localhost']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function suites(): iterable
    {
        yield 'tabulation, que le navigateur retire' => ['/%09/phishing.test', '/'];
        yield 'double barre' => ['//evil.test', '/'];
        yield 'barre inverse' => ['/%5Cevil.test', '/'];
        yield 'retour à la ligne' => ['/%0A/evil.test', '/'];
        yield 'adresse complète' => ['https://evil.test/', '/'];
        yield 'chemin du site' => ['/parcours/decouverte', '/parcours/decouverte'];
    }

    #[DataProvider('suites')]
    public function testRedirectionOuverteImpossible(string $suite, string $attendu): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('GET', '/inscription?suite='.$suite);
        $client->submitForm('Créer mon compte', [
            'registration_form[displayName]' => 'Gorm',
            'registration_form[email]' => 'gorm2@example.test',
            'registration_form[plainPassword]' => 'une-longue-phrase',
        ], serverParameters: ['HTTP_ORIGIN' => 'http://localhost']);

        $this->assertResponseRedirects($attendu);
    }

    public function testInscriptionLibreSansChampDeCode(): void
    {
        $client = static::createClient();
        $client->request('GET', '/inscription');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('input[name="registration_form[invitationCode]"]');
        $this->assertSelectorTextContains('.form-card', 'Gratuit.');
    }

    public function testUnLienDInvitationPreRemplitLeCodeEtRattacheALaCohorte(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $this->createCohort('iut-2026');

        $client->request('GET', '/inscription?code=iut-2026');
        $this->assertSelectorExists('input[name="registration_form[invitationCode]"][value="iut-2026"]', 'Le code du lien est pré-rempli.');
        $client->submitForm('Créer mon compte', [
            'registration_form[displayName]' => 'Gorm',
            'registration_form[email]' => 'gorm@example.test',
            'registration_form[plainPassword]' => 'une-longue-phrase',
        ], serverParameters: ['HTTP_ORIGIN' => 'http://localhost']);

        $this->assertResponseRedirects('/');
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'gorm@example.test']);
        $this->assertSame('iut-2026', $user?->getCohort()?->getCode());
    }

    /** L'équipe est prévenue de chaque inscription (REGISTRATION_ALERT_EMAIL). */
    public function testUneAlerteEstEnvoyeeAChaqueInscription(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $this->createCohort('iut-2026');

        $client->request('GET', '/inscription?code=iut-2026');
        $client->submitForm('Créer mon compte', [
            'registration_form[displayName]' => 'Gorm',
            'registration_form[email]' => 'gorm@example.test',
            'registration_form[plainPassword]' => 'une-longue-phrase',
        ], serverParameters: ['HTTP_ORIGIN' => 'http://localhost']);
        $this->assertResponseRedirects('/');

        $this->assertEmailCount(2, message: 'L\'alerte à l\'équipe, puis la confirmation d\'adresse à l\'apprenant.');
        $email = $this->getMailerMessage(0);
        $this->assertInstanceOf(Email::class, $email);
        $this->assertEmailAddressContains($email, 'to', 'alerte@example.test');
        $this->assertEmailAddressContains($email, 'from', 'ne-pas-repondre@forelse.fr');
        $this->assertEmailSubjectContains($email, 'Gorm');
        $this->assertEmailTextBodyContains($email, 'gorm@example.test');
        $this->assertEmailTextBodyContains($email, 'iut-2026');
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'gorm@example.test']);
        $this->assertEmailTextBodyContains($email, 'http://localhost/admin/apprenants/'.$user?->getId(), 'Lien vers la fiche admin.');
    }

    public function testSansDestinataireAucuneAlerteNePart(): void
    {
        $this->setEnv('REGISTRATION_ALERT_EMAIL', '');
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('GET', '/inscription');
        $client->submitForm('Créer mon compte', [
            'registration_form[displayName]' => 'Gorm',
            'registration_form[email]' => 'gorm@example.test',
            'registration_form[plainPassword]' => 'une-longue-phrase',
        ], serverParameters: ['HTTP_ORIGIN' => 'http://localhost']);

        $this->assertResponseRedirects('/');
        $this->assertEmailCount(1, message: 'Seulement la confirmation d\'adresse.');
        $this->assertEmailAddressContains($this->getMailerMessage(0), 'to', 'gorm@example.test');
    }

    public function testUnCodeInconnuOuUneCohorteFermeeSontRefuses(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $this->createCohort('fermee-2025', active: false);
        $fields = [
            'registration_form[displayName]' => 'Gorm',
            'registration_form[email]' => 'gorm@example.test',
            'registration_form[plainPassword]' => 'une-longue-phrase',
        ];

        foreach (['bts-2026', 'fermee-2025'] as $code) {
            $client->request('GET', '/inscription?code='.$code);
            $client->submitForm('Créer mon compte', $fields, serverParameters: ['HTTP_ORIGIN' => 'http://localhost']);
            $this->assertResponseStatusCodeSame(422, $code);
            $this->assertSelectorTextContains('.form-card', 'Code d\'invitation inconnu ou expiré.');
        }
    }

    public function testBetaFermeeUnCodeDInvitationValideEstExige(): void
    {
        $this->inviteOnly();
        $client = static::createClient();
        $this->resetDatabase();
        $this->createCohort('iut-2026');

        $client->request('GET', '/inscription');
        $this->assertSelectorTextContains('.form-card', 'Bêta sur invitation.');
        $this->assertSelectorExists('.form-card a[href="/#liste-attente"]', 'Sans code, la liste d\'attente est proposée.');

        $fields = [
            'registration_form[displayName]' => 'Gorm',
            'registration_form[email]' => 'gorm@example.test',
            'registration_form[plainPassword]' => 'une-longue-phrase',
        ];
        $client->submitForm('Créer mon compte', $fields + ['registration_form[invitationCode]' => ''], serverParameters: ['HTTP_ORIGIN' => 'http://localhost']);
        $this->assertResponseStatusCodeSame(422, 'Sans code, pas de compte.');
        $this->assertSelectorTextContains('.form-card', 'Indiquez votre code d\'invitation.');

        $client->submitForm('Créer mon compte', $fields + ['registration_form[invitationCode]' => ' IUT-2026 '], serverParameters: ['HTTP_ORIGIN' => 'http://localhost']);
        $this->assertResponseRedirects('/');
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'gorm@example.test']);
        $this->assertSame('iut-2026', $user?->getCohort()?->getCode(), 'Le code est normalisé (casse, espaces).');
    }

    public function testDixCodesInconnusFermentLesCodesDepuisCetteAdresse(): void
    {
        $this->inviteOnly();
        $client = static::createClient();
        $this->resetDatabase();
        $this->createCohort('iut-2026');
        // Les compteurs vivent dans un cache en mémoire, vidé entre deux requêtes de test : on épuise le quota avant
        // la première requête, sur le même kernel.
        $limiter = static::getContainer()->get('limiter.invitation_code')->create('127.0.0.1');
        for ($i = 1; $i <= 10; ++$i) {
            $limiter->consume();
        }

        // Même le bon code : sinon, sa réussite dirait qu'on l'a trouvé.
        $client->request('POST', '/inscription', ['registration_form' => [
            'displayName' => 'Gorm', 'email' => 'gorm@example.test', 'plainPassword' => 'une-longue-phrase',
            'invitationCode' => 'iut-2026', '_token' => 'csrf-token',
        ]], server: ['HTTP_ORIGIN' => 'http://localhost']);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('.form-card', 'Trop de codes d\'invitation essayés');
        $this->assertNull(static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'gorm@example.test']));
    }

    public function testUnCodeInconnuCompteParmiLesEssais(): void
    {
        $this->inviteOnly();
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('POST', '/inscription', ['registration_form' => [
            'displayName' => 'Gorm', 'email' => 'gorm@example.test', 'plainPassword' => 'une-longue-phrase',
            'invitationCode' => 'iut-2025', '_token' => 'csrf-token',
        ]], server: ['HTTP_ORIGIN' => 'http://localhost']);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame(9, static::getContainer()->get('limiter.invitation_code')->create('127.0.0.1')->consume(0)->getRemainingTokens());
    }
}
