<?php

namespace App\Entity;

use App\Repository\AdresseRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AdresseRepository::class)]
class Adresse
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Client $client = null;

    #[ORM\Column(length: 60)]
    private string $libelle = '';

    #[ORM\Column(length: 180)]
    private string $rue = '';

    #[ORM\Column(length: 10)]
    private string $codePostal = '';

    #[ORM\Column(length: 120)]
    private string $ville = '';

    public function getId(): ?int { return $this->id; }
    public function getClient(): ?Client { return $this->client; }
    public function setClient(?Client $c): static { $this->client = $c; return $this; }
    public function getLibelle(): string { return $this->libelle; }
    public function setLibelle(string $v): static { $this->libelle = $v; return $this; }
    public function getRue(): string { return $this->rue; }
    public function setRue(string $v): static { $this->rue = $v; return $this; }
    public function getCodePostal(): string { return $this->codePostal; }
    public function setCodePostal(string $v): static { $this->codePostal = $v; return $this; }
    public function getVille(): string { return $this->ville; }
    public function setVille(string $v): static { $this->ville = $v; return $this; }
}
