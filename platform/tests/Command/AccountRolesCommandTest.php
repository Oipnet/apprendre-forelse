<?php

namespace App\Tests\Command;

use App\Entity\User;
use App\Tests\DatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** app:admin et app:auteur : donnent ou retirent un rôle, sans toucher aux autres. */
final class AccountRolesCommandTest extends KernelTestCase
{
    use DatabaseTrait;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetDatabase();
    }

    private function console(string $command, array $input): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find($command));
        $tester->execute($input);

        return $tester;
    }

    /** @return list<string> les rôles du compte, relus en base */
    private function rolesOf(string $email): array
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $roles = $entityManager->getRepository(User::class)->findOneBy(['email' => $email])?->getRoles() ?? [];
        sort($roles);

        return $roles;
    }

    public function testAppAdminDonnePuisRetireLAccesEnGardantLesAutresRoles(): void
    {
        $this->createUser()->setRoles([User::ROLE_CHEF_COHORTE]);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $tester = $this->console('app:admin', ['email' => 'ada@example.test']);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('a désormais accès', $tester->getDisplay(true));
        $this->assertSame([User::ROLE_ADMIN, User::ROLE_CHEF_COHORTE, 'ROLE_USER'], $this->rolesOf('ada@example.test'));

        // Donné deux fois : pas de doublon.
        $this->console('app:admin', ['email' => 'ada@example.test']);
        $this->assertSame([User::ROLE_ADMIN, User::ROLE_CHEF_COHORTE, 'ROLE_USER'], $this->rolesOf('ada@example.test'));

        $tester = $this->console('app:admin', ['email' => 'ada@example.test', '--retirer' => true]);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('n\'a plus accès', $tester->getDisplay(true));
        $this->assertSame([User::ROLE_CHEF_COHORTE, 'ROLE_USER'], $this->rolesOf('ada@example.test'));
    }

    public function testAppAuteurDonnePuisRetireLeDroitDEcrire(): void
    {
        $this->createUser();

        $tester = $this->console('app:auteur', ['email' => 'ada@example.test']);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('rôle d\'administrateur', $tester->getDisplay(true), 'Le risque est rappelé.');
        $this->assertSame([User::ROLE_AUTEUR, 'ROLE_USER'], $this->rolesOf('ada@example.test'));

        $this->console('app:auteur', ['email' => 'ada@example.test', '--retirer' => true]);
        $this->assertSame(['ROLE_USER'], $this->rolesOf('ada@example.test'));
    }

    public function testUnCompteInconnuEstSignale(): void
    {
        foreach (['app:admin', 'app:auteur'] as $command) {
            $tester = $this->console($command, ['email' => 'personne@example.test']);
            $this->assertSame(Command::FAILURE, $tester->getStatusCode(), $command);
            $this->assertStringContainsString('Aucun compte avec l\'adresse « personne@example.test »', $tester->getDisplay(true));
        }
    }
}
