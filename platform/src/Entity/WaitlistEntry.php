<?php

namespace App\Entity;

use App\Repository\WaitlistEntryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Adresse laissée sur la page d'accueil pour être prévenue de l'ouverture (bêta fermée).
 * Une adresse n'est enregistrée qu'une fois.
 */
#[ORM\Entity(repositoryClass: WaitlistEntryRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_WAITLIST_EMAIL', fields: ['email'])]
class WaitlistEntry implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $email)
    {
        $this->email = self::normalize($email);
        $this->createdAt = new \DateTimeImmutable();
    }

    /** Même adresse, quelle que soit la casse ou les espaces autour. */
    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function __toString(): string
    {
        return $this->email;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
