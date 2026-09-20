<?php

namespace App\Entity;

use App\Repository\AvisRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AvisRepository::class)]
class Avis
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Biere::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Biere $biere = null;

    #[ORM\ManyToOne(targetEntity: Client::class)]
    private ?Client $client = null;

    /** Affiché tel quel — vecteur XSS (chapitre 3, F3.6). */
    #[ORM\Column(length: 80)]
    private string $auteurNom = '';

    /** Non validé au départ : 11/5 s'affiche (chapitre 5, F5.3). */
    #[ORM\Column]
    private int $note = 5;

    #[ORM\Column(length: 120)]
    private string $titre = '';

    /** Affiché avec |raw (chapitre 3, F3.1). */
    #[ORM\Column(type: 'text')]
    private string $corps = '';

    /** Rendu en href sur la fiche — vecteur du Boss du chapitre 3 (schéma javascript:). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $siteAuteur = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $publieLe;

    /** Aucune modération au départ. */
    #[ORM\Column]
    private bool $valide = true;

    public function __construct()
    {
        $this->publieLe = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getBiere(): ?Biere { return $this->biere; }
    public function setBiere(?Biere $b): static { $this->biere = $b; return $this; }
    public function getClient(): ?Client { return $this->client; }
    public function setClient(?Client $c): static { $this->client = $c; return $this; }
    public function getAuteurNom(): string { return $this->auteurNom; }
    public function setAuteurNom(string $v): static { $this->auteurNom = $v; return $this; }
    public function getNote(): int { return $this->note; }
    public function setNote(int $n): static { $this->note = $n; return $this; }
    public function getTitre(): string { return $this->titre; }
    public function setTitre(string $v): static { $this->titre = $v; return $this; }
    public function getCorps(): string { return $this->corps; }
    public function setCorps(string $v): static { $this->corps = $v; return $this; }
    public function getSiteAuteur(): ?string { return $this->siteAuteur; }
    public function setSiteAuteur(?string $v): static { $this->siteAuteur = $v; return $this; }
    public function getPublieLe(): \DateTimeImmutable { return $this->publieLe; }
    public function setPublieLe(\DateTimeImmutable $d): static { $this->publieLe = $d; return $this; }
    public function isValide(): bool { return $this->valide; }
    public function setValide(bool $v): static { $this->valide = $v; return $this; }
}
