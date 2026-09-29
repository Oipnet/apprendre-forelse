<?php

namespace App\Entity;

use App\Repository\ExternalIdentityRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un compte chez un fournisseur d'identité (GitHub) lié à un compte de la plateforme : on s'y connecte sans mot de
 * passe. Le compte est retrouvé par l'identifiant du fournisseur, jamais par l'email, qui peut changer chez lui.
 */
#[ORM\Entity(repositoryClass: ExternalIdentityRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_EXTERNAL_IDENTITY', fields: ['provider', 'providerUserId'])]
#[ORM\UniqueConstraint(name: 'UNIQ_EXTERNAL_IDENTITY_USER', fields: ['user', 'provider'])]
class ExternalIdentity
{
    public const string GITHUB = 'github';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Supprimée avec le compte (clé étrangère), comme sa progression. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 20)]
    private string $provider;

    /** Identifiant du compte chez le fournisseur : stable, contrairement au nom d'utilisateur et à l'email. */
    #[ORM\Column(length: 64)]
    private string $providerUserId;

    /** Nom d'utilisateur chez le fournisseur, pour l'affichage ; mis à jour à chaque connexion. */
    #[ORM\Column(length: 100)]
    private string $username;

    #[ORM\Column]
    private \DateTimeImmutable $linkedAt;

    public function __construct(User $user, string $provider, string $providerUserId, string $username, \DateTimeImmutable $linkedAt)
    {
        $this->user = $user;
        $this->provider = $provider;
        $this->providerUserId = $providerUserId;
        $this->username = $username;
        $this->linkedAt = $linkedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getProviderUserId(): string
    {
        return $this->providerUserId;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function setUsername(string $username): static
    {
        $this->username = $username;

        return $this;
    }

    public function getLinkedAt(): \DateTimeImmutable
    {
        return $this->linkedAt;
    }
}
