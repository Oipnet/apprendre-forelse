<?php

namespace App\Entity;

use App\Repository\ClientRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: ClientRepository::class)]
class Client implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private string $email = '';

    #[ORM\Column(length: 120)]
    private string $nom = '';

    /** MD5 sans sel au départ (chapitre 7, F7.1). */
    #[ORM\Column(length: 255)]
    private string $motDePasse = '';

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $roles = [];

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $telephone = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $adresse = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $codePostal = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $ville = null;

    /** La trace du compte fantôme (chapitre 1). */
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column]
    private bool $actif = true;

    /** md5(email.jour) au départ (chapitre 10, F10.1) ; puis un hachage de jeton aléatoire. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $jetonReinitialisation = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $jetonExpireLe = null;

    /** Vide au départ ; secret TOTP au chapitre 10. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $secret2fa = null;

    /** @var list<string> liste de hachages de codes de secours à usage unique (chapitre 10). */
    #[ORM\Column(type: 'json')]
    private array $codesDeSecours = [];

    public function __construct()
    {
        $this->creeLe = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): static { $this->email = $email; return $this; }
    public function getNom(): string { return $this->nom; }
    public function setNom(string $nom): static { $this->nom = $nom; return $this; }

    public function getMotDePasse(): string { return $this->motDePasse; }
    public function setMotDePasse(string $v): static { $this->motDePasse = $v; return $this; }

    /** @return list<string> */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static { $this->roles = $roles; return $this; }

    public function getTelephone(): ?string { return $this->telephone; }
    public function setTelephone(?string $v): static { $this->telephone = $v; return $this; }
    public function getAdresse(): ?string { return $this->adresse; }
    public function setAdresse(?string $v): static { $this->adresse = $v; return $this; }
    public function getCodePostal(): ?string { return $this->codePostal; }
    public function setCodePostal(?string $v): static { $this->codePostal = $v; return $this; }
    public function getVille(): ?string { return $this->ville; }
    public function setVille(?string $v): static { $this->ville = $v; return $this; }
    public function getCreeLe(): \DateTimeImmutable { return $this->creeLe; }
    public function setCreeLe(\DateTimeImmutable $d): static { $this->creeLe = $d; return $this; }
    public function isActif(): bool { return $this->actif; }
    public function setActif(bool $actif): static { $this->actif = $actif; return $this; }
    public function getJetonReinitialisation(): ?string { return $this->jetonReinitialisation; }
    public function setJetonReinitialisation(?string $v): static { $this->jetonReinitialisation = $v; return $this; }
    public function getJetonExpireLe(): ?\DateTimeImmutable { return $this->jetonExpireLe; }
    public function setJetonExpireLe(?\DateTimeImmutable $d): static { $this->jetonExpireLe = $d; return $this; }
    public function getSecret2fa(): ?string { return $this->secret2fa; }
    public function setSecret2fa(?string $v): static { $this->secret2fa = $v; return $this; }
    /** @return list<string> */
    public function getCodesDeSecours(): array { return $this->codesDeSecours; }
    /** @param list<string> $codes */
    public function setCodesDeSecours(array $codes): static { $this->codesDeSecours = $codes; return $this; }

    // --- Contrat Symfony Security ---

    public function getUserIdentifier(): string { return $this->email; }

    /** Le hachage stocké, relu par le composant Security. */
    public function getPassword(): ?string { return $this->motDePasse; }

    public function eraseCredentials(): void
    {
        // Rien à effacer : pas de mot de passe en clair conservé en mémoire.
    }
}
