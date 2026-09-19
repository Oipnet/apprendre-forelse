<?php

namespace App\Entity;

use App\Repository\BiereRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BiereRepository::class)]
class Biere
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80, unique: true)]
    private string $slug = '';

    #[ORM\Column(length: 120)]
    private string $nom = '';

    #[ORM\Column(length: 40)]
    private string $style = '';

    #[ORM\Column]
    private float $degre = 0.0;

    #[ORM\Column(type: 'decimal', precision: 6, scale: 2)]
    private string $prix = '0.00';

    #[ORM\Column(type: 'text')]
    private string $description = '';

    /** Le champ piège : rendu avec |raw sur la fiche et l'accueil (chapitre 3, F3.1/F3.2). */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $descriptionHtml = null;

    #[ORM\Column]
    private int $stock = 0;

    /** Les bières désactivées ne sont pas au catalogue — l'injection SQL du ch. 2 les révèle. */
    #[ORM\Column]
    private bool $actif = true;

    #[ORM\ManyToOne(targetEntity: Etiquette::class)]
    private ?Etiquette $etiquette = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $creeLe;

    public function __construct()
    {
        $this->creeLe = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): static { $this->slug = $slug; return $this; }
    public function getNom(): string { return $this->nom; }
    public function setNom(string $nom): static { $this->nom = $nom; return $this; }
    public function getStyle(): string { return $this->style; }
    public function setStyle(string $style): static { $this->style = $style; return $this; }
    public function getDegre(): float { return $this->degre; }
    public function setDegre(float $degre): static { $this->degre = $degre; return $this; }
    public function getPrix(): string { return $this->prix; }
    public function setPrix(string $prix): static { $this->prix = $prix; return $this; }
    public function getDescription(): string { return $this->description; }
    public function setDescription(string $description): static { $this->description = $description; return $this; }
    public function getDescriptionHtml(): ?string { return $this->descriptionHtml; }
    public function setDescriptionHtml(?string $v): static { $this->descriptionHtml = $v; return $this; }
    public function getStock(): int { return $this->stock; }
    public function setStock(int $stock): static { $this->stock = $stock; return $this; }
    public function isActif(): bool { return $this->actif; }
    public function setActif(bool $actif): static { $this->actif = $actif; return $this; }
    public function getEtiquette(): ?Etiquette { return $this->etiquette; }
    public function setEtiquette(?Etiquette $e): static { $this->etiquette = $e; return $this; }
    public function getCreeLe(): \DateTimeImmutable { return $this->creeLe; }
    public function setCreeLe(\DateTimeImmutable $d): static { $this->creeLe = $d; return $this; }
}
