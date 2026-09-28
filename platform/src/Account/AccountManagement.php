<?php

namespace App\Account;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Les écritures du compte par son titulaire : le mot de passe (depuis le compte ou le lien « mot de passe oublié ») et
 * la suppression. La session reste l'affaire du contrôleur : c'est lui qui reconnecte ou déconnecte.
 */
final readonly class AccountManagement
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $hasher,
    ) {
    }

    public function changePassword(User $user, string $plainPassword): void
    {
        $user->setPassword($this->hasher->hashPassword($user, $plainPassword));
        $this->entityManager->flush();
    }

    /** Progression, avis et accès partent avec le compte ; les achats restent, sans lien vers lui (pièces comptables). */
    public function delete(User $user): void
    {
        $this->entityManager->remove($user);
        $this->entityManager->flush();
    }
}
