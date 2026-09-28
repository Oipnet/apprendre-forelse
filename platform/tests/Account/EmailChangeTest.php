<?php

namespace App\Tests\Account;

use App\Account\EmailChange;
use App\Account\EmailChangeNotice;
use App\Account\EmailChangeOutcome;
use App\Account\EmailConfirmationOutcome;
use App\Account\EmailVerifier;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\DatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class EmailChangeTest extends KernelTestCase
{
    use DatabaseTrait;

    private const string PASSWORD = 'une-longue-phrase';

    private EntityManagerInterface $entityManager;
    private User $ada;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = $this->resetDatabase();
        $this->ada = $this->createUser();
        $this->ada->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($this->ada, self::PASSWORD));
        $this->entityManager->flush();
    }

    private function emailChange(?MailerInterface $mailer = null, int $limit = 5): EmailChange
    {
        $container = static::getContainer();
        $verifier = null === $mailer ? $container->get(EmailVerifier::class) : new EmailVerifier($container->get(UriSigner::class), $container->get(UrlGeneratorInterface::class), $mailer, new NullLogger(), 'ne-pas-repondre@example.test');

        return new EmailChange(
            $this->entityManager,
            $container->get(UserRepository::class),
            $container->get(UserPasswordHasherInterface::class),
            $verifier,
            $container->get(EmailChangeNotice::class),
            new MockClock('2026-03-02 10:00'),
            new RateLimiterFactory(['id' => 'confirmation', 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '1 hour'], new InMemoryStorage()),
        );
    }

    /** Le lien envoyé par le dernier email, comme l'ouvrirait l'apprenant. */
    private function openedLink(): Request
    {
        $messages = $this->getMailerMessages();
        $email = end($messages);
        $this->assertInstanceOf(Email::class, $email);
        preg_match('#http://localhost/compte/confirmer-email\?\S+#', (string) $email->getTextBody(), $match);
        $this->assertNotEmpty($match, 'Le lien est dans l\'email.');

        return Request::create(html_entity_decode($match[0]));
    }

    public function testSansChangementDAdresseLeProfilEstEnregistre(): void
    {
        $this->assertSame(EmailChangeOutcome::Saved, $this->emailChange()->request($this->ada, ' Ada L. ', 'ADA@example.test', null));

        $this->assertSame('Ada L.', $this->ada->getDisplayName());
        $this->assertEmailCount(0);
    }

    public function testLeMotDePasseEstDemandeAvantDeDireSiLAdresseEstPrise(): void
    {
        $this->createUser('prise@example.test', 'Autre');

        $this->assertSame(EmailChangeOutcome::PasswordRequired, $this->emailChange()->request($this->ada, 'Ada L.', 'prise@example.test', 'faux'));
        $this->assertSame('Ada', $this->ada->getDisplayName(), 'Rien n\'est enregistré.');
        $this->assertSame(EmailChangeOutcome::EmailTaken, $this->emailChange()->request($this->ada, 'Ada L.', 'prise@example.test', self::PASSWORD));
        $this->assertEmailCount(0);
    }

    public function testUneAdressePriseLEstQuelleQueSoitLaCasse(): void
    {
        $this->createUser('prise@example.test', 'Autre');

        $this->assertSame(EmailChangeOutcome::EmailTaken, $this->emailChange()->request($this->ada, 'Ada', 'Prise@Example.test', self::PASSWORD));
        $this->assertEmailCount(0);
    }

    public function testLaNouvelleAdresseEstEnregistreeEnMinuscules(): void
    {
        $emailChange = $this->emailChange();
        $this->assertSame(EmailChangeOutcome::Sent, $emailChange->request($this->ada, 'Ada', ' Ada.Nouvelle@Example.test ', self::PASSWORD));
        $this->assertEmailAddressContains($this->getMailerMessage(), 'to', 'ada.nouvelle@example.test');

        $this->assertSame(EmailConfirmationOutcome::Changed, $emailChange->confirm($this->openedLink()));
        $this->assertSame('ada.nouvelle@example.test', $this->ada->getEmail());
    }

    public function testSansMotDePasseChangerDAdresseDemandeUneConnexionGithubRecente(): void
    {
        $bob = (new User())->setEmail('bob@example.test')->setDisplayName('Bob');
        $this->entityManager->persist($bob);
        $this->entityManager->flush();

        $this->assertSame(EmailChangeOutcome::PasswordRequired, $this->emailChange()->request($bob, 'Bob', 'bob.nouveau@example.test', null));
        $this->assertEmailCount(0);
        $this->assertSame(EmailChangeOutcome::Sent, $this->emailChange()->request($bob, 'Bob', 'bob.nouveau@example.test', null, recentlySignedIn: true));
    }

    public function testLeLienPartALaNouvelleAdresse(): void
    {
        $this->assertSame(EmailChangeOutcome::Sent, $this->emailChange()->request($this->ada, 'Ada', 'nouvelle@example.test', self::PASSWORD));

        $this->assertSame('ada@example.test', $this->ada->getEmail(), 'Inchangée jusqu\'à la confirmation.');
        $this->assertEmailCount(1);
        $this->assertEmailAddressContains($this->getMailerMessage(), 'to', 'nouvelle@example.test');
    }

    public function testLesEnvoisSontLimitesParCompte(): void
    {
        $emailChange = $this->emailChange(limit: 1);

        $this->assertSame(EmailChangeOutcome::Sent, $emailChange->resend($this->ada));
        $this->assertSame(EmailChangeOutcome::Throttled, $emailChange->resend($this->ada));
        $this->assertSame(EmailChangeOutcome::Throttled, $emailChange->request($this->ada, 'Ada', 'nouvelle@example.test', self::PASSWORD));
        $this->assertEmailCount(1);
    }

    public function testUnEnvoiEnEchecEstSignale(): void
    {
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willThrowException(new TransportException('SMTP en panne'));

        $this->assertSame(EmailChangeOutcome::SendFailed, $this->emailChange($mailer)->request($this->ada, 'Ada', 'nouvelle@example.test', self::PASSWORD));
    }

    public function testRienARenvoyerPourUneAdresseConfirmee(): void
    {
        $this->ada->confirmEmail('ada@example.test', new \DateTimeImmutable());

        $this->assertSame(EmailChangeOutcome::AlreadyConfirmed, $this->emailChange()->resend($this->ada));
        $this->assertEmailCount(0);
    }

    public function testLeLienConfirmeLaNouvelleAdresseEtPrevientLAncienne(): void
    {
        $emailChange = $this->emailChange();
        $emailChange->request($this->ada, 'Ada', 'nouvelle@example.test', self::PASSWORD);
        $link = $this->openedLink();

        $this->assertSame(EmailConfirmationOutcome::Changed, $emailChange->confirm($link));
        $this->assertSame('nouvelle@example.test', $this->ada->getEmail());
        $this->assertEquals(new \DateTimeImmutable('2026-03-02 10:00'), $this->ada->getEmailVerifiedAt());
        $this->assertEmailAddressContains($this->getMailerMessage(1), 'to', 'ada@example.test');
        $this->assertSame(EmailConfirmationOutcome::AlreadyConfirmed, $emailChange->confirm($link), 'Rejoué.');
    }

    public function testLeLienDeLAdresseDuCompteLaConfirme(): void
    {
        $emailChange = $this->emailChange();
        $emailChange->resend($this->ada);

        $this->assertSame(EmailConfirmationOutcome::Confirmed, $emailChange->confirm($this->openedLink()));
        $this->assertTrue($this->ada->isEmailVerified());
        $this->assertEmailCount(1, message: 'Aucun avis : l\'adresse n\'a pas changé.');
    }

    public function testUnLienAltereOuCaducEstRefuse(): void
    {
        $emailChange = $this->emailChange();
        $emailChange->request($this->ada, 'Ada', 'nouvelle@example.test', self::PASSWORD);
        $link = $this->openedLink();

        $altered = Request::create(str_replace('nouvelle%40', 'pirate%40', $link->getUri()));
        $this->assertSame(EmailConfirmationOutcome::Invalid, $emailChange->confirm($altered));

        // Un autre changement confirmé entre-temps rend le lien caduc.
        $emailChange->request($this->ada, 'Ada', 'autre@example.test', self::PASSWORD);
        $emailChange->confirm($this->openedLink());
        $this->assertSame(EmailConfirmationOutcome::Outdated, $emailChange->confirm($link));
    }

    public function testUneAdressePriseEntreTempsEstRefusee(): void
    {
        $emailChange = $this->emailChange();
        $emailChange->request($this->ada, 'Ada', 'nouvelle@example.test', self::PASSWORD);
        $this->createUser('nouvelle@example.test', 'Plus rapide');

        $this->assertSame(EmailConfirmationOutcome::EmailTaken, $emailChange->confirm($this->openedLink()));
        $this->assertSame('ada@example.test', $this->ada->getEmail());
    }
}
