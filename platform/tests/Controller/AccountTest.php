<?php

namespace App\Tests\Controller;

use App\Entity\ExerciseProgress;
use App\Entity\PriceKind;
use App\Entity\Purchase;
use App\Entity\User;
use App\Payment\WithdrawalWaiver;
use App\Repository\UserRepository;
use App\Tests\DatabaseTrait;
use App\Tests\PacksTrait;
use App\Tests\Payment\FakePaymentGateway;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** La page « Mon compte ». Pack de test « payant » : chapitre « libre » (e1), puis « complet » (e2, e3). */
final class AccountTest extends WebTestCase
{
    use DatabaseTrait;
    use PacksTrait;

    private const array ORIGIN = ['HTTP_ORIGIN' => 'http://localhost'];
    private const string PASSWORD = 'une-longue-phrase';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->usePaidPack();
        FakePaymentGateway::reset();
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    protected function tearDown(): void
    {
        $this->restorePacks();
        parent::tearDown();
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** Un apprenant avec un vrai mot de passe haché, connecté. */
    private function login(string $email = 'ada@example.test'): User
    {
        $user = $this->createUser($email);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $this->entityManager()->flush();
        $this->client->loginUser($user);

        return $user;
    }

    private function reload(User $user): ?User
    {
        $this->entityManager()->clear();

        return static::getContainer()->get(UserRepository::class)->find($user->getId());
    }

    /** Le lien de confirmation du dernier email envoyé. */
    private function confirmationLink(): string
    {
        $email = $this->getMailerMessage();
        $this->assertNotNull($email);
        preg_match('#http://localhost(/compte/confirmer-email\?\S+)#', (string) $email->getTextBody(), $match);
        $this->assertNotEmpty($match, 'Le lien est dans l\'email.');

        return html_entity_decode($match[1]);
    }

    public function testLaPageDitOuReprendre(): void
    {
        $ada = $this->login();
        $crawler = $this->client->request('GET', '/compte');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#progression .ac-tag', 'Gratuit', 'Parcours sans tarif : ouvert à tout compte.');
        $this->assertSame('/parcours/payant/e1', $crawler->filter('#progression')->selectLink('Commencer →')->attr('href'));
        $this->assertSelectorNotExists('.ac-featured', 'Rien en cours.');

        $done = new ExerciseProgress($ada, 'payant', 'e1');
        $done->complete(0, 50);
        $this->entityManager()->persist($done);
        $this->entityManager()->flush();

        $crawler = $this->client->request('GET', '/compte');
        $this->assertSelectorTextContains('.ac-featured', '1 / 3 exercices');
        $this->assertSelectorTextContains('.ac-featured', '50 XP');
        $this->assertSelectorTextContains('.ac-kpis', 'exercice réussi 1');
        $resume = $crawler->filter('#progression')->selectLink('Reprendre →');
        $this->assertCount(1, $resume);
        $this->assertSame('/parcours/payant/e2', $resume->attr('href'), 'Au premier exercice pas encore réussi.');
    }

    public function testChangerDAdresseDemandeUneConfirmation(): void
    {
        $ada = $this->login();
        $this->client->request('GET', '/compte');
        $this->client->submitForm('Enregistrer', [
            'profile_form[displayName]' => 'Ada L.',
            'profile_form[email]' => 'ada.nouvelle@example.test',
            'profile_form[currentPassword]' => self::PASSWORD,
        ], serverParameters: self::ORIGIN);

        $this->assertResponseRedirects('/compte#profil', 303);
        $this->assertEmailCount(1);
        $this->assertEmailAddressContains($this->getMailerMessage(), 'to', 'ada.nouvelle@example.test');
        $user = $this->reload($ada);
        $this->assertSame('Ada L.', $user->getDisplayName());
        $this->assertSame('ada@example.test', $user->getEmail(), 'L\'adresse ne change qu\'une fois confirmée.');

        $link = $this->confirmationLink();
        $this->client->request('GET', $link);
        $this->assertResponseRedirects('/compte');
        $user = $this->reload($ada);
        $this->assertSame('ada.nouvelle@example.test', $user->getEmail());
        $this->assertTrue($user->isEmailVerified());
        // L'ancienne adresse est prévenue du changement.
        $this->assertEmailCount(1);
        $this->assertEmailAddressContains($this->getMailerMessage(), 'to', 'ada@example.test');
        $this->assertEmailTextBodyContains($this->getMailerMessage(), 'ada.nouvelle@example.test');

        $this->client->followRedirect();
        $this->assertResponseIsSuccessful('Toujours connecté avec la nouvelle adresse.');
        $this->assertSelectorTextContains('.flash', 'ada.nouvelle@example.test');
        $this->client->request('GET', '/compte');
        $this->assertResponseIsSuccessful();

        // Le lien a servi : rejoué, il ne change plus rien.
        $this->client->request('GET', $link);
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash', 'déjà confirmée');
    }

    /** Sur un poste partagé ou avec une session volée, on ne s'approprie pas le compte en changeant son adresse. */
    public function testChangerDAdresseDemandeLeMotDePasse(): void
    {
        $ada = $this->login();
        foreach (['', 'pas-le-bon'] as $motDePasse) {
            $this->client->request('GET', '/compte');
            $this->client->submitForm('Enregistrer', [
                'profile_form[displayName]' => 'Ada',
                'profile_form[email]' => 'pirate@example.test',
                'profile_form[currentPassword]' => $motDePasse,
            ], serverParameters: self::ORIGIN);

            $this->assertResponseStatusCodeSame(422);
            $this->assertSelectorTextContains('#profil', 'mot de passe actuel est demandé');
            $this->assertEmailCount(0);
        }
        $this->assertSame('ada@example.test', $this->reload($ada)->getEmail());

        // Le pseudo, lui, se change sans mot de passe.
        $this->client->request('GET', '/compte');
        $this->client->submitForm('Enregistrer', ['profile_form[displayName]' => 'Ada L.', 'profile_form[email]' => 'ada@example.test'], serverParameters: self::ORIGIN);
        $this->assertResponseRedirects('/compte#profil', 303);
    }

    public function testUneAdresseDejaPriseEstRefusee(): void
    {
        $this->createUser('gorm@example.test', 'Gorm');
        $this->login();
        $this->client->request('GET', '/compte');
        $this->client->submitForm('Enregistrer', [
            'profile_form[displayName]' => 'Ada',
            'profile_form[email]' => 'gorm@example.test',
            'profile_form[currentPassword]' => self::PASSWORD,
        ], serverParameters: self::ORIGIN);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('#profil', 'Un compte existe déjà avec cet email.');
        $this->assertEmailCount(0);
    }

    public function testUnLienDeConfirmationModifieOuPerimeEstRefuse(): void
    {
        $ada = $this->login();
        $this->client->request('GET', '/compte');
        $this->assertSelectorExists('#confirmation', 'Adresse pas encore confirmée : on le signale.');
        $this->client->submitForm('Renvoyer le lien', serverParameters: self::ORIGIN);
        $this->assertResponseRedirects('/compte', 303);
        $link = $this->confirmationLink();
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash', 'Un nouveau lien de confirmation');

        $this->client->request('GET', str_replace('ada%40example.test', 'pirate%40example.test', $link));
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash', 'pas valide');
        $this->assertFalse($this->reload($ada)->isEmailVerified());

        $this->client->request('GET', $link);
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash', 'Adresse confirmée');
        $this->assertTrue($this->reload($ada)->isEmailVerified());
        $this->assertSelectorNotExists('#confirmation');
    }

    public function testChangerDeMotDePasse(): void
    {
        $ada = $this->login();
        $before = $ada->getPassword();
        $this->client->request('GET', '/compte');
        $this->client->submitForm('Changer le mot de passe', [
            'password_form[currentPassword]' => 'pas-le-bon',
            'password_form[plainPassword]' => 'un-nouveau-mot-de-passe',
        ], serverParameters: self::ORIGIN);
        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('#mot-de-passe', 'Ce n\'est pas votre mot de passe actuel.');

        $this->client->submitForm('Changer le mot de passe', [
            'password_form[currentPassword]' => self::PASSWORD,
            'password_form[plainPassword]' => 'un-nouveau-mot-de-passe',
        ], serverParameters: self::ORIGIN);
        $this->assertResponseRedirects('/compte#mot-de-passe', 303);
        $this->assertNotSame($before, $this->reload($ada)->getPassword());
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful('Toujours connecté après le changement.');
    }

    public function testSupprimerSonCompteGardeLesAchats(): void
    {
        $ada = $this->login();
        $purchase = new Purchase($ada, 'payant', 4900, PriceKind::Normal, WithdrawalWaiver::TEXT, new \DateTimeImmutable());
        $this->entityManager()->persist($purchase);
        $this->entityManager()->persist(new ExerciseProgress($ada, 'payant', 'e1'));
        $this->entityManager()->flush();

        $this->client->request('GET', '/compte');
        $this->client->submitForm('Supprimer définitivement', ['delete_account_form[password]' => 'pas-le-bon'], serverParameters: self::ORIGIN);
        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorExists('#suppression details[open]', 'La zone reste ouverte, erreur visible.');
        $this->assertNotNull($this->reload($ada));

        $this->client->submitForm('Supprimer définitivement', ['delete_account_form[password]' => self::PASSWORD], serverParameters: self::ORIGIN);
        $this->assertResponseRedirects('/', 303);
        $this->assertNull($this->reload($ada));
        $this->assertSame(0, $this->entityManager()->getRepository(ExerciseProgress::class)->count(), 'La progression part avec le compte.');
        $kept = $this->entityManager()->getRepository(Purchase::class)->findAll();
        $this->assertCount(1, $kept, 'L\'achat reste : pièce comptable.');
        $this->assertNull($kept[0]->getUser());
        $this->assertSame('ada@example.test', $kept[0]->getCustomerEmail());

        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash', 'Votre compte a été supprimé');
        $this->client->request('GET', '/compte');
        $this->assertResponseRedirects('/connexion', message: 'Déconnecté.');
    }

    public function testUneFactureNeSeTelechargeQueParSonProprietaire(): void
    {
        $gorm = $this->createUser('gorm@example.test', 'Gorm');
        $purchase = new Purchase($gorm, 'payant', 4900, PriceKind::Normal, WithdrawalWaiver::TEXT, new \DateTimeImmutable());
        $purchase->attachInvoice('in_123');
        $this->entityManager()->persist($purchase);
        $this->entityManager()->flush();

        $this->login();
        $this->client->request('GET', '/compte/achats/'.$purchase->getId().'/facture');
        $this->assertResponseStatusCodeSame(404);

        $this->client->loginUser($gorm);
        $this->client->request('GET', '/compte/achats/'.$purchase->getId().'/facture');
        $this->assertResponseRedirects('https://pay.stripe.test/invoice/in_123/pdf');
    }
}
