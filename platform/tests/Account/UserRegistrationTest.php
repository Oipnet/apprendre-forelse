<?php

namespace App\Tests\Account;

use App\Account\UserRegistration;
use App\Entity\TrackAccess;
use App\Entity\User;
use App\Tests\DatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserRegistrationTest extends KernelTestCase
{
    use DatabaseTrait;

    private EntityManagerInterface $entityManager;
    private UserRegistration $registration;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = $this->resetDatabase();
        $this->registration = static::getContainer()->get(UserRegistration::class);
    }

    private function newUser(string $email = 'gorm@example.test'): User
    {
        return (new User())->setEmail($email)->setDisplayName('Gorm');
    }

    public function testLeCompteEstCreeAvecSonMotDePasseHacheLAlerteEtLaConfirmation(): void
    {
        $user = $this->newUser();

        $result = $this->registration->register($user, 'une-longue-phrase', null);

        $this->assertNotNull($user->getId(), 'Enregistré.');
        $this->assertNull($user->getCohort());
        $this->assertNotSame('une-longue-phrase', $user->getPassword());
        $this->assertTrue(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, 'une-longue-phrase'));
        $this->assertTrue($result->confirmationSent);
        $this->assertEmailCount(2, message: 'L\'alerte à l\'équipe, puis la confirmation d\'adresse.');
        $this->assertEmailAddressContains($this->getMailerMessage(0), 'to', 'alerte@example.test');
        $this->assertEmailAddressContains($this->getMailerMessage(1), 'to', 'gorm@example.test');
    }

    public function testUnCodeRattacheALaCohorteEtOuvreSesParcours(): void
    {
        $this->createCohort('iut-2026')->setAvailableTrackIds(['decouverte']);
        $this->entityManager->flush();
        $user = $this->newUser();

        $this->registration->register($user, 'une-longue-phrase', ' iut-2026 ');

        $this->assertSame('iut-2026', $user->getCohort()?->getCode());
        $accesses = $this->entityManager->getRepository(TrackAccess::class)->findBy(['user' => $user]);
        $this->assertCount(1, $accesses, 'Cohorte financée par l\'établissement : son parcours s\'ouvre.');
        $this->assertSame('decouverte', $accesses[0]->getTrackId());
    }

    public function testUnCodeInactifNeRattacheAAucuneCohorte(): void
    {
        $this->createCohort('ferme', active: false);
        $user = $this->newUser();

        $this->registration->register($user, 'une-longue-phrase', 'ferme');

        $this->assertNull($user->getCohort());
    }

    public function testLeTitulaireDUneAdressePriseEstPrevenu(): void
    {
        $this->createUser('ada@example.test');

        $this->registration->notifyHolder($this->newUser('ada@example.test'));
        $this->registration->notifyHolder($this->newUser('personne@example.test'));

        $this->assertEmailCount(1);
        $this->assertEmailAddressContains($this->getMailerMessage(0), 'to', 'ada@example.test');
    }
}
