<?php

namespace App\Entity;

use App\Repository\TentativeConnexionRepository;
use Doctrine\ORM\Mapping as ORM;

/** La table de la force brute (chapitre 1) et la matière du chapitre 17. */
#[ORM\Entity(repositoryClass: TentativeConnexionRepository::class)]
class TentativeConnexion
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $email = '';

    #[ORM\Column(length: 45)]
    private string $ip = '';

    #[ORM\Column]
    private bool $reussie = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $faiteLe;

    #[ORM\Column(length: 255)]
    private string $agent = '';

    public function __construct()
    {
        $this->faiteLe = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getEmail(): string { return $this->email; }
    public function setEmail(string $v): static { $this->email = $v; return $this; }
    public function getIp(): string { return $this->ip; }
    public function setIp(string $v): static { $this->ip = $v; return $this; }
    public function isReussie(): bool { return $this->reussie; }
    public function setReussie(bool $v): static { $this->reussie = $v; return $this; }
    public function getFaiteLe(): \DateTimeImmutable { return $this->faiteLe; }
    public function setFaiteLe(\DateTimeImmutable $d): static { $this->faiteLe = $d; return $this; }
    public function getAgent(): string { return $this->agent; }
    public function setAgent(string $v): static { $this->agent = $v; return $this; }
}
