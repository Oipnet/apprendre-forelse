<?php

namespace App\Entity;

use App\Repository\CommandeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CommandeRepository::class)]
class Commande
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, unique: true)]
    private string $reference = '';

    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Client $client = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column(length: 20)]
    private string $statut = 'panier';

    /** Recopié depuis la requête au départ, au lieu d'être recalculé (chapitre 5, F5.1). */
    #[ORM\Column(type: 'decimal', precision: 8, scale: 2)]
    private string $total = '0.00';

    #[ORM\Column(type: 'text')]
    private string $adresseLivraison = '';

    /** @var Collection<int, LigneCommande> */
    #[ORM\OneToMany(targetEntity: LigneCommande::class, mappedBy: 'commande', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $lignes;

    public function __construct()
    {
        $this->creeLe = new \DateTimeImmutable();
        $this->lignes = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getReference(): string { return $this->reference; }
    public function setReference(string $v): static { $this->reference = $v; return $this; }
    public function getClient(): ?Client { return $this->client; }
    public function setClient(?Client $c): static { $this->client = $c; return $this; }
    public function getCreeLe(): \DateTimeImmutable { return $this->creeLe; }
    public function setCreeLe(\DateTimeImmutable $d): static { $this->creeLe = $d; return $this; }
    public function getStatut(): string { return $this->statut; }
    public function setStatut(string $v): static { $this->statut = $v; return $this; }
    public function getTotal(): string { return $this->total; }
    public function setTotal(string $v): static { $this->total = $v; return $this; }
    public function getAdresseLivraison(): string { return $this->adresseLivraison; }
    public function setAdresseLivraison(string $v): static { $this->adresseLivraison = $v; return $this; }

    /** @return Collection<int, LigneCommande> */
    public function getLignes(): Collection { return $this->lignes; }

    public function ajouterLigne(LigneCommande $ligne): static
    {
        if (!$this->lignes->contains($ligne)) {
            $this->lignes->add($ligne);
            $ligne->setCommande($this);
        }

        return $this;
    }

    /** La somme réelle des lignes. L'écart avec getTotal() trahit la faille du chapitre 5. */
    public function totalCalcule(): string
    {
        $somme = '0.00';
        foreach ($this->lignes as $ligne) {
            $somme = bcadd($somme, bcmul($ligne->getPrixUnitaire(), (string) $ligne->getQuantite(), 2), 2);
        }

        return $somme;
    }
}
