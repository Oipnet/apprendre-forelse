<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class LigneCommande
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Commande::class, inversedBy: 'lignes')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Commande $commande = null;

    #[ORM\ManyToOne(targetEntity: Biere::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Biere $biere = null;

    #[ORM\Column]
    private int $quantite = 1;

    /** Vient du formulaire au départ (chapitre 5, F5.2) au lieu de Biere::$prix. */
    #[ORM\Column(type: 'decimal', precision: 6, scale: 2)]
    private string $prixUnitaire = '0.00';

    public function getId(): ?int { return $this->id; }
    public function getCommande(): ?Commande { return $this->commande; }
    public function setCommande(?Commande $c): static { $this->commande = $c; return $this; }
    public function getBiere(): ?Biere { return $this->biere; }
    public function setBiere(?Biere $b): static { $this->biere = $b; return $this; }
    public function getQuantite(): int { return $this->quantite; }
    public function setQuantite(int $q): static { $this->quantite = $q; return $this; }
    public function getPrixUnitaire(): string { return $this->prixUnitaire; }
    public function setPrixUnitaire(string $p): static { $this->prixUnitaire = $p; return $this; }
}
