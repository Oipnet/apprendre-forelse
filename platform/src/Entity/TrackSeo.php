<?php

namespace App\Entity;

use App\Repository\TrackSeoRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Title et description d'une page de parcours, saisis dans l'admin quand la génération automatique ne convient pas
 * (voir TrackSeoText). Le parcours vit dans un pack : on le désigne par son identifiant, comme pour un tarif.
 */
#[ORM\Entity(repositoryClass: TrackSeoRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_TRACK_SEO_TRACK', fields: ['trackId'])]
#[UniqueEntity(fields: ['trackId'], message: 'Ce parcours a déjà son référencement.')]
class TrackSeo
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    private ?string $trackId = null;

    /** Title de la page, sans le suffixe « | <marque> » (ajouté au rendu). Vide : title généré. */
    #[ORM\Column(length: 60, nullable: true)]
    #[Assert\Length(max: 60)]
    private ?string $seoTitle = null;

    /** Meta description (et og:description). Vide : description générée. */
    #[ORM\Column(length: 155, nullable: true)]
    #[Assert\Length(max: 155)]
    private ?string $seoDescription = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(?string $trackId = null)
    {
        $this->trackId = $trackId;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTrackId(): ?string
    {
        return $this->trackId;
    }

    public function setTrackId(string $trackId): static
    {
        $this->trackId = $trackId;

        return $this;
    }

    public function getSeoTitle(): ?string
    {
        return $this->seoTitle;
    }

    public function setSeoTitle(?string $seoTitle): static
    {
        $this->seoTitle = self::blankToNull($seoTitle);
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getSeoDescription(): ?string
    {
        return $this->seoDescription;
    }

    public function setSeoDescription(?string $seoDescription): static
    {
        $this->seoDescription = self::blankToNull($seoDescription);
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private static function blankToNull(?string $text): ?string
    {
        $text = null === $text ? '' : trim((string) preg_replace('/\s+/u', ' ', $text));

        return '' === $text ? null : $text;
    }
}
