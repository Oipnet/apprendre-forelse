<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:admin',
    description: 'Donne (ou retire) l\'accès au tableau de bord d\'administration (/admin).',
)]
final class AdminCommand
{
    public function __construct(
        private readonly UserRepository $utilisateurs,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Adresse e-mail du compte')] string $email,
        #[Option('Retirer l\'accès au lieu de le donner')] bool $retirer = false,
    ): int {
        $utilisateur = $this->utilisateurs->findOneBy(['email' => $email]);
        if (!$utilisateur instanceof User) {
            $io->error(sprintf('Aucun compte avec l\'adresse « %s ».', $email));

            return Command::FAILURE;
        }

        $roles = array_values(array_diff($utilisateur->getRoles(), ['ROLE_USER', User::ROLE_ADMIN]));
        $utilisateur->setRoles($retirer ? $roles : [...$roles, User::ROLE_ADMIN]);
        $this->entityManager->flush();

        $io->success(sprintf('%s %s au tableau de bord d\'administration.', $email, $retirer ? 'n\'a plus accès' : 'a désormais accès'));

        return Command::SUCCESS;
    }
}
