<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
// « user » est un mot réservé de PostgreSQL : le nom doit être cité.
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
#[UniqueEntity(fields: ['email'], message: 'Un compte existe déjà avec cet email.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    /** Droit d'écrire des exercices dans l'atelier des auteurs. */
    public const string ROLE_AUTEUR = 'ROLE_AUTEUR';
    /** Accès au tableau de bord d'administration (/admin). */
    public const string ROLE_ADMIN = 'ROLE_ADMIN';
    /** Accès à l'espace des chefs de cohorte (/cohorte) : suivre ses cohortes et choisir leurs parcours. */
    public const string ROLE_CHEF_COHORTE = 'ROLE_CHEF_COHORTE';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank(message: 'Indiquez votre email.')]
    #[Assert\Email(message: 'Cet email n\'est pas valide.')]
    private ?string $email = null;

    /**
     * @var list<string> The user roles
     */
    #[ORM\Column]
    private array $roles = [];

    /**
     * @var string The hashed password
     */
    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(length: 40)]
    #[Assert\NotBlank(message: 'Choisissez un pseudo.')]
    #[Assert\Length(max: 40)]
    private ?string $displayName = null;

    /** Somme des XP gagnées sur les exercices réussis. */
    #[ORM\Column(options: ['default' => 0])]
    private int $xp = 0;

    /** Cohorte rejointe à l'inscription (code d'invitation) ; null pour une inscription libre. */
    #[ORM\ManyToOne(inversedBy: 'users')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Cohort $cohort = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Adresse confirmée par le lien envoyé à l'inscription (ou au changement d'adresse) ; null tant qu'elle ne l'est pas. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    /**
     * Supprimer un compte (RGPD, tableau de bord) emporte sa progression et ses retours : la cascade
     * est portée par l'ORM, en plus des clés étrangères de la base.
     *
     * @var Collection<int, ExerciseProgress>
     */
    #[ORM\OneToMany(targetEntity: ExerciseProgress::class, mappedBy: 'user', cascade: ['remove'])]
    private Collection $progress;

    /** @var Collection<int, Feedback> */
    #[ORM\OneToMany(targetEntity: Feedback::class, mappedBy: 'user', cascade: ['remove'])]
    private Collection $feedbacks;

    /**
     * Accès aux parcours (achat, cohorte, offert) : supprimés avec le compte. Les achats, eux, restent (pièces comptables).
     *
     * @var Collection<int, TrackAccess>
     */
    #[ORM\OneToMany(targetEntity: TrackAccess::class, mappedBy: 'user', cascade: ['remove'])]
    private Collection $accesses;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->progress = new ArrayCollection();
        $this->feedbacks = new ArrayCollection();
        $this->accesses = new ArrayCollection();
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): static
    {
        $this->displayName = $displayName;

        return $this;
    }

    public function getCohort(): ?Cohort
    {
        return $this->cohort;
    }

    public function setCohort(?Cohort $cohort): static
    {
        $this->cohort = $cohort;

        return $this;
    }

    public function getXp(): int
    {
        return $this->xp;
    }

    public function addXp(int $xp): static
    {
        $this->xp += $xp;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getEmailVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function isEmailVerified(): bool
    {
        return null !== $this->emailVerifiedAt;
    }

    /** L'adresse (nouvelle ou non) vient d'être confirmée par un lien envoyé à cette adresse. */
    public function confirmEmail(string $email, \DateTimeImmutable $now): static
    {
        $this->email = $email;
        $this->emailVerifiedAt = $now;

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function __toString(): string
    {
        return (string) $this->displayName;
    }

    /**
     * Ensure the session doesn't contain actual password hashes by CRC32C-hashing them, as supported since Symfony 7.3.
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0" . self::class . "\0password"] = hash('crc32c', $this->password);
        
        return $data;
    }
}
