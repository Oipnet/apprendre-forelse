<?php

namespace App\Entity;

use App\Repository\EtiquetteRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EtiquetteRepository::class)]
class Etiquette
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $nomOriginal = '';

    /** Relatif à public/uploads/etiquettes/ (chapitre 6, F6.2). */
    #[ORM\Column(length: 255)]
    private string $chemin = '';

    /** Recopié depuis le navigateur, jamais vérifié (chapitre 6, F6.1). */
    #[ORM\Column(length: 100)]
    private string $typeMime = '';

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $deposeLe;

    #[ORM\ManyToOne(targetEntity: Client::class)]
    private ?Client $deposePar = null;

    public function __construct()
    {
        $this->deposeLe = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getNomOriginal(): string { return $this->nomOriginal; }
    public function setNomOriginal(string $v): static { $this->nomOriginal = $v; return $this; }
    public function getChemin(): string { return $this->chemin; }
    public function setChemin(string $v): static { $this->chemin = $v; return $this; }
    public function getTypeMime(): string { return $this->typeMime; }
    public function setTypeMime(string $v): static { $this->typeMime = $v; return $this; }
    public function getDeposeLe(): \DateTimeImmutable { return $this->deposeLe; }
    public function setDeposeLe(\DateTimeImmutable $d): static { $this->deposeLe = $d; return $this; }
    public function getDeposePar(): ?Client { return $this->deposePar; }
    public function setDeposePar(?Client $c): static { $this->deposePar = $c; return $this; }
}
