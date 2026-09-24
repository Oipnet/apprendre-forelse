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
    name: 'app:auteur',
    description: 'Donne (ou retire) le droit d\'écrire des exercices dans l\'atelier.',
)]
final class AuteurCommand
{
    public function __construct(
        private readonly UserRepository $utilisateurs,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Adresse e-mail du compte')] string $email,
        #[Option('Retirer le droit au lieu de le donner')] bool $retirer = false,
    ): int {
        $utilisateur = $this->utilisateurs->findOneBy(['email' => $email]);
        if (!$utilisateur instanceof User) {
            $io->error(sprintf('Aucun compte avec l\'adresse « %s ».', $email));

            return Command::FAILURE;
        }

        $roles = array_values(array_diff($utilisateur->getRoles(), ['ROLE_USER', User::ROLE_AUTEUR]));
        $utilisateur->setRoles($retirer ? $roles : [...$roles, User::ROLE_AUTEUR]);
        $this->entityManager->flush();

        $io->success(sprintf('%s %s l\'atelier des auteurs.', $email, $retirer ? 'n\'a plus accès à' : 'a désormais accès à'));
        if (!$retirer) {
            $io->note('Sur des packs modifiables, l\'atelier exécute sur ce serveur les tests que l\'auteur écrit, avec les secrets de la plateforme à portée : c\'est un rôle d\'administrateur. Les compose.yaml montent les packs en lecture seule.');
        }

        return Command::SUCCESS;
    }
}
